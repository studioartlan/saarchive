<?php
/*

    saArchive
    Copyright (C) 2010 Studio Artlan

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.

	For any questions contact xmak@studioartlan.com.
	
*/

/*!
 \class saArchive esaarchive.php
 \ingroup saarchive extension
 \brief Archives eZ Publish objects

Archives eZ Publish object based on INI settings in saarchive.ini. Supports archive actions such as:

- change section
- move node
- hide enode
- delete node


*/


class saArchive
{

	const INI_NAME = 'saarchive.ini';
	
	const ACTION_CHANGE_SECTION = 'change_section';
	const ACTION_MOVE = 'move';
	const ACTION_HIDE = 'hide';
	const ACTION_DELETE = 'delete';
	
	const FETCH_TYPE_TREE = 'tree';
	const FETCH_TYPE_LIST = 'list';
	const FETCH_TYPE_LIST_RECURSIVE = 'list_recursive';
	
	const FILTER_OLDER_THAN = 'older_than';
	const FILTER_OLDER_THAN_REGEX = "/^older_than:(\d+)d(\d+)m(\d+)y$/";
	
	const FILTER_MORE_THAN = 'more_than';
	const FILTER_MORE_THAN_REGEX = "/^more_than:(\d+)$/";

	const FILTER_OPERATOR_IN = 'in';
	const FILTER_OPERATOR_NOT_IN = 'not_in';
		
	const CLASS_FILTER_TYPE_INCLUDE = 'include';
	const CLASS_FILTER_TYPE_EXCLUDE = 'exclude';
	
	const DELETE_ACTION_DELETE = "delete";
	const DELETE_ACTION_TRASH = "move_to_trash";

	private static $_INI = null;
	static $Cli = null;
	static $Debug = null;
	static $UseDebug = false;

	private static $_AllowDelete = false;
	private static $_DeleteAction = false;
	private static $_AvailableArchiveJobs = null;
	private static $_ContainerClasses = array();
	
	private $_Jobs;

	private $_ProcessedActionNodes = array();
	private $_ProcessedTotalNodes = 0;
	
	function saArchive()
	{
		if (!self::$_INI)
		{
			self::$_INI = eZINI::instance(self::INI_NAME);
			if (self::$_INI)
			{
				self::$_INI->load();
			}
			else
			{
				self::_Message("Could not open INI file " . self::INI_NAME);
			}
		}

	}
	
	/*!
	 \private

	 Parses the INI setting and checks for their validity. All the checking is done here so no additional checking is done when procesing jobs.
	 Saves the jobs in the _Jobs class variable.

	 \returns true there was no error in INI settings, false othervise.
	*/
	private function _ParseSettings()
	{

		$result = true;
		
		if (self::_ParseGlobalSettings())
		{
			foreach (self::$_AvailableArchiveJobs as $jobName)
			{
				$job = self::_ParseJobSettings($jobName);
				if ($job)
					$this->_Jobs[$jobName] = $job;
				else
					$result = false;
			}
		}
		
		return $result;
				
	}

	/*!
	 \private
	 \static

	 Parses the global INI setting and checks for their validity. All the checking is done here so no additional checking is done when procesing jobs.

	 \returns true if there was no error in INI settings, false othervise.
	*/
	private static function _ParseGlobalSettings()
	{

		self::$_AvailableArchiveJobs = self::$_INI->variable('ArchiveSettings', 'AvailableArchiveJobs');


		$deleteAction = self::$_INI->variable('ArchiveSettings', 'DeleteAction');

		if  ( ($deleteAction != self::DELETE_ACTION_DELETE) && ($deleteAction != self::DELETE_ACTION_TRASH))
		{
			self::_Message("Invalid delete action in global settings.");
			return false;
		}
		
		self::$_DeleteAction = $deleteAction;
		
		self::$_ContainerClasses = self::$_INI->variable('ArchiveSettings', 'ContainerClasses');

		self::$_AllowDelete = ( self::$_INI->variable('ArchiveSettings', 'AllowDelete') == 'yes');
		
		return true;
		
	}
	
	/*!
	 \private
	 \static

	 \param jobName The name of the job for which to parse the settings
	 
	 Parses the INI setting sections for each job and checs for their validity. All the checking is done here so no additional checking is done when procesing jobs.

	 \returns array with the parsed data for the job if there was no error in INI settings, false othervise.
	*/
	private static function _ParseJobSettings($jobName)
	{

		if (self::$_INI->hasGroup($jobName))
		{
			$result = array();
			
			$parentNodes = self::$_INI->variable($jobName, 'ParentNodes');
			$nodeFilters = self::$_INI->variable($jobName, 'NodeFilters');
			$sectionFilters = self::$_INI->variable($jobName, 'SectionFilters');
			$globalClassFilterType = self::$_INI->variable($jobName, 'GlobalClassFilterType');
			$globalClassFilterArray = self::$_INI->variable($jobName, 'GlobalClassFilterArray');
			$classFilterTypes = self::$_INI->variable($jobName, 'ClassFilterTypes');
			$classFilterArrays = self::$_INI->variable($jobName, 'ClassFilterArrays');
			
			$result['parent_nodes'] = array();
			$result['section_filters'] = array();
			
			if (!$parentNodes)
			{
				self::_Message("No parent nodes specified for job: $jobName");
				return false;
			}

			if (!$nodeFilters)
			{
				self::_Message("No filters specified for job: $jobName");
				return false;
			}

			
			
			// Check parent nodes
			foreach ($parentNodes as $nodeID => $fetchType)
			{

				// Is the fetch type valid
				if (
					($fetchType != self::FETCH_TYPE_TREE)
					&& ($fetchType != self::FETCH_TYPE_LIST)
					&& ($fetchType != self::FETCH_TYPE_LIST_RECURSIVE)
				)
				{
					self::_Message("Invalid fetch type '$fetchType' for node ID '$nodeID' for job: $jobName");
					return false;
				}

				// Do we have a filter for this node ID
				if ( !in_array($nodeID, array_keys($nodeFilters)) )
				{
					self::_Message("There's no filter for node '$nodeID' for job: $jobName");
					return false;
				}
				
				// Does the node exist
				if (!eZContentObjectTreeNode::fetch($nodeID))
				{
					self::_Message("Non exsistent parent node to archive '$nodeID' for job: $jobName");
					return false;
				}

				$result['parent_nodes'][$nodeID] = array('fetch_function' => $fetchType);
			}
			

			// Check nodes filters
			foreach ($nodeFilters as $nodeID => $filter)
			{
				// Is the node ID in parent nodes
				if ( !in_array($nodeID, array_keys($parentNodes)) )
				{
					self::_Message("Node $nodeID in node filters doesn't exsist in parent nodes for job: $jobName");
					return false;
				}

				if (preg_match(self::FILTER_OLDER_THAN_REGEX, $filter, $regexResults))
				{
//	print_r($regexResults);
					// Get the timestamp for the preiod
					$timePeriod = ($regexResults[1] + $regexResults[2] * 30 + $regexResults[3] * 365) * 24 * 60 * 60;
					$result['parent_nodes'][$nodeID]['filter'] = array('older_than', $timePeriod);
				}
				elseif (preg_match(self::FILTER_MORE_THAN_REGEX, $filter, $regexResults))
				{
//	print_r($regexResults);
					$result['parent_nodes'][$nodeID]['filter'] = array('more_than', $regexResults[1]);
				}
				else
				{
					self::_Message("Invalid filter '[$nodeID]=$filter' for job: $jobName");
					return false;
				}
				
			}
#NodeFilters[<parent_node_id>]=older_than:<days_number>d<months_number>m<years_number>y|more_than:<count>

			// Check section filters
			foreach ($sectionFilters as $sectionID => $filterOperator)
			{
				
				// Is the operator valid
				if ( ($filterOperator != self::FILTER_OPERATOR_IN) && ($filterOperator != self::FILTER_OPERATOR_NOT_IN) )
				{
					self::_Message("Invalid operator '$filterOperator' in section filter with ID '$sectionID' for job: $jobName");
					return false;
				}

				// Does the section exist
				if (!eZSection::fetch($sectionID))
				{
					self::_Message("Non exsistent filter section with ID '$sectionID' for job: $jobName");
					return false;
				}

				$result['section_filters'][$filterOperator][] = $sectionID;
			}

			// Check Global class filter type
			if ( ($globalClassFilterType != self::CLASS_FILTER_TYPE_INCLUDE) && ($globalClassFilterType != self::CLASS_FILTER_TYPE_EXCLUDE) )
			{
				self::_Message("Invalid type '$globalClassFilterType' in Global class filter type for job: $jobName");
				return false;
			}


			// Check Global class filter array
			foreach ($globalClassFilterArray as $classID)
			{
				// Check for class existance
				if ( (!eZContentClass::fetch($classID)) && (!eZContentClass::fetchByIdentifier($classID)) )
				{
					self::_Message("Non existant class with ID '$classID' in Global class filter array for job: $jobName");
					return false;
				}

			}

			// Set for each parent node class filter type and array from Global job settings
			if ($globalClassFilterType && $globalClassFilterArray)
			{
				foreach ($parentNodes as $nodeID => $fetchType)
				{
					$result['parent_nodes'][$nodeID]['class_filter_type'] = $globalClassFilterType;
					$result['parent_nodes'][$nodeID]['class_filter_array'] = $globalClassFilterArray;
				}
			}


			// Check class filter types for each parent node
			foreach ($classFilterTypes as $nodeID => $filterType)
			{
				// Is the node ID in parent nodes
				if ( !in_array($nodeID, array_keys($parentNodes)) )
				{
					self::_Message("Node $nodeID in class filter type doesn't exsist in parent nodes for job: $jobName");
					return false;
				}
				
				// Is the type valid
				if ( ($filterType != self::CLASS_FILTER_TYPE_INCLUDE) && ($filterType != self::CLASS_FILTER_TYPE_EXCLUDE) )
				{
					self::_Message("Invalid type '$filterType' in class filter type with ID $nodeID for job: $jobName");
					return false;
				}

				// Class filter type for each node overrides the Global class filter settings
				$result['parent_nodes'][$nodeID]['class_filter_type'] = $filterType;
			}

			// Check class filter array for each parent node
			foreach ($classFilterArrays as $nodeID => $filterArray)
			{
				// Is the node ID in parent nodes
				if ( !in_array($nodeID, array_keys($parentNodes)) )
				{
					self::_Message("Node $nodeID in class filter type doesn't exsist in parent nodes for job: $jobName");
					return false;
				}
				
				$classIDs = explode(',', $filterArray);
				
				// Check for class existance
				foreach ($classIDs as $classID)
				{
					if ( (!eZContentClass::fetch($classID)) && (!eZContentClass::fetchByIdentifier($classID)) )
					{
						self::_Message("Non existant class with ID '$classID' in class filter array with ID $nodeID for job: $jobName");
						return false;
					}
				}

				// Class filter array for each node overrides the Global class filter settings
				$result['parent_nodes'][$nodeID]['class_filter_array'] = $classIDs;
			}

			
			$archiveActions = self::$_INI->variable($jobName, 'ArchiveActions');

			if ($archiveActions)
			{
				$result['actions'] = array();
				
				foreach ($archiveActions as $action)
				{
					switch ($action)
					{
						case self::ACTION_CHANGE_SECTION:
							$sectionMappings = self::$_INI->variable($jobName, 'SectionMappings');
							
							if ($sectionMappings)
							{
								$result['actions'][] = $action;
								$result['section_mappings'] = array();
								
								foreach ($sectionMappings as $fromSectionID => $toSectionID)
								{
									if (!eZSection::fetch($fromSectionID))
									{
										self::_Message("Non exsistent 'from' section with ID '$fromSectionID' in action '$action' for job: $jobName");
										return false;
									}
									if (!eZSection::fetch($toSectionID))
									{
										self::_Message("Non exsistent 'to' section with ID '$toSectionID' in action '$action' for job: $jobName");
										return false;
									}
	
									$result['section_mappings'][$fromSectionID] = $toSectionID;
								}
								
							}
							else
							{
								self::_Message("No section mappings setting in action '$action' for job: $jobName");
								return false;
							}
						break;
						case self::ACTION_MOVE:
							$result['actions'][] = $action;
						break;
						case self::ACTION_HIDE:
							$result['actions'][] = $action;
						break;
						case self::ACTION_DELETE:
							$result['actions'][] = $action;
						break;
	
						default:
							self::_Message("Unrecognized action '$action' for job: $jobName");
							return false;
						break;
					}
	
				}
				
				return $result;
			}
			else
			{
				self::_Message("No actions to perform for job: '$jobName'");
				return false;
			}

			
		}
		else
		{
			self::_Message("No INI job group '$jobName'");
			return false;
		}

	}


	/*!
	 
	 Parses the settings and starts the processing of the jobs

	 \returns true if there was no error in INI settings or job processing, false othervise.
	*/
	function ProcessJobs()
	{

		if ($this->_ParseSettings())
			return $this->_ProcessJobs();
		else
			return false;
	}

	/*!
	 \private

	 Loops the _Jobs class variable and starts the processing for each job.

	 \returns true if there was no error in processing jobs, false othervise.
	*/
	private function _ProcessJobs()
	{

		if ($this->_Jobs)
		{
			$result = true;
			
			foreach ($this->_Jobs as $jobName => $job)
			{
				if (!$this->_ProcessJob($jobName, $job))
				{
					self::_Message("Error occured in processing job $jobName");
					$result = false;
				}
			}

			self::_Message("Total processed nodes: " . $this->_ProcessedTotalNodes);

			return $result;
			
		}
		else
		{
			self::_Message("No Jobs to process");
			return false;
		}
	}


	/*!
	 \private

	 Processes the job. Here is where the real work is done.

	 \param jobName The name of the job
	 \param job The array containing job processing data
	 
	 \returns Always returns true, since all the checking is done when parsing the INI settings.
	*/
	private function _ProcessJob($jobName, $job)
	{

//		print_r($job);

		self::_Message("Processing archive job: '$jobName'");
		self::_Message(self::_PrintJob($jobName, $job));
		
		$parentNodes = $job['parent_nodes'];
		
		foreach ($parentNodes as $nodeID => $fetchParams)
		{
			$this->_ProcessNodes(&$job, $nodeID, &$fetchParams);
		}
		
		return true;
	}


	/*!
	 \private

	 Fetches the nodes for the given parent node and fetch params, and processes them.

	 \param job The job
	 \param parentNodeID The node id of the node from which to fetch
	 \param fetchParams The parameters for fetch
	 
	 \returns Always returns true, since all the checking is done when parsing the INI settings.
	*/
	private function _ProcessNodes($job, $parentNodeID, $fetchParams)
	{

		$current_time = time();
		
		$params = array();
		$attributeFilter= array();
			
		if (
			($fetchParams['fetch_function'] == self::FETCH_TYPE_LIST)
			|| ($fetchParams['fetch_function'] == self::FETCH_TYPE_LIST_RECURSIVE)
		)
			$params['Depth'] = 1;

		if ($fetchParams['filter'][0] == 'more_than')
			$params['Offset'] = $fetchParams['filter'][1];

		if (isset($fetchParams['class_filter_type']))
			$params['ClassFilterType'] = $fetchParams['class_filter_type'];
		if (isset($fetchParams['class_filter_array']))
			$params['ClassFilterArray'] = $fetchParams['class_filter_array'];


		if (isset($job['section_filters']))
		{
			foreach ($job['section_filters'] as $filterOperator => $sections)
			{
				$attributeFilter[] = array('section', $filterOperator, $sections);
			}
		}


		if ($fetchParams['fetch_function'] == self::FETCH_TYPE_LIST_RECURSIVE)
		{
			$containerParams['ClassFilterType'] = 'include';
			$containerParams['ClassFilterArray'] = self::$_ContainerClasses;
			$containerParams['Depth'] = 1;

			if ($attributeFilter)
				$containerParams['AttributeFilter'] = $attributeFilter;

			unset($containerNodes);
			$containerNodes =& eZContentObjectTreeNode::subTreeByNodeID(&$containerParams, $parentNodeID);
			unset($containerParams);
		}
		else $containerNodes = false;


		if ($containerNodes)
		{
			foreach ($containerNodes as $containerNode)
			{
				$this->_ProcessNodes(&$job, $containerNode->attribute('node_id'), &$fetchParams);
			}
		}
		unset($containerNodes);
			

		if ($fetchParams['filter'][0] == 'older_than')
			$attributeFilter[] = array('published', '<', $current_time - $fetchParams['filter'][1]);
							
		if ($attributeFilter)
		{
			array_unshift($attributeFilter, 'and');
			$params['AttributeFilter'] = $attributeFilter;
		}
			
#echo "#". date("d.m.Y", $current_time) . "#\n";
#echo "#". $fetchParams['filter'][1] . "#\n";
#echo "#". date("d.m.Y", $current_time - $fetchParams['filter'][1]) . "#\n";

		$params['SortBy'] = array('published', false);
			
		self::_Message("Fetching nodes for node ID: '$parentNodeID' ($fetchParams[fetch_function])");

#echo "#$parentNodeID#\n";
#print_r($params);
#exit;

		unset($nodes);
		$nodes =& eZContentObjectTreeNode::subTreeByNodeID(&$params, $parentNodeID);
		unset($params);
		$nodesCount = count($nodes);

		self::_Message("Number of fetched nodes: $nodesCount");
			
		if ($nodesCount > 0)
		{
			self::_Message("Archiving nodes in parent node $parentNodeID...");

			foreach ($nodes as $index => $node)
			{
				self::_Message("Processing node " . $node->attribute('name'));
				$this->_ProcessNode($job, $node, $fetchParams, $index, $nodesCount);
			}
				
		}
		else
		{
			self::_Message("Nothing to process.");
		}
		
		unset($nodes);
		
		return true;
		
	}


	/*!
	 \private

	 Processes a node with job actions

	 \param job The job
	 \param node The node to process
	 \param fetchParams The parameters for fetch
	 
	 \returns Always returns true, since all the checking is done when parsing the INI settings.
	*/
	private function _ProcessNode($job, $node, $fetchParams, $index, $count)
	{
		unset($object);
		$object =& $node->attribute('object');
		$nodeID = $node->attribute('node_id');
		
		foreach ($job['actions'] as $action);
		{
			switch ($action)
			{
					case self::ACTION_CHANGE_SECTION:

						$sectionID = $object->attribute('section_id');
						
						if ( array_key_exists($sectionID, $job['section_mappings']) )
						{
								$newSectionID = $job['section_mappings'][$sectionID];
								
								self::_Message("Parent node $nodeID ($index/$count): Changing section ID from $sectionID to $newSectionID for object " . $object->attribute('name') . " (ID: " . $object->attribute('id') . ")");
								$object->setAttribute('section_id', $newSectionID);
								$object->store();
								$this->ProcessedNodes[$action]++;
								$this->_ProcessedTotalNodes++;
						}
					break;
					case self::ACTION_MOVE:
						$result['actions'][] = $action;
					break;
					case self::ACTION_HIDE:
						$result['actions'][] = $action;
					break;
					case self::ACTION_DELETE:
						$result['actions'][] = $action;
					break;

					default:
						// This shouldn't happen if INI parsing did the job								
						self::_Message("Unknown action while processing job $jobName. This should not happen, please contact the saarchive author");
					break;
			}
		}
		unset($object);
		
		return true;
	}
	

	/*!
	 \private
	 \static

	 Generates a readable output of the job data

	 \param jobName The name of the job
	 \param job The array containing job processing data
	 
	 \returns string with readable output of job data
	*/
	private static function _PrintJob($jobName, $job)
	{

		
		$output = "##############################################\n";
		$output .= "# Job settings for $jobName:\n";
		$output .= saLibUtils::print_recursive($job, 0) . "\n";
		$output .= "##############################################\n";
		
		return $output;

	}
	
## Output end Debug functions

// TODO: napraviti output i debug sa eZDebug klasom - proucit ju.
	private static function _Message($message)
	{
		if (self::$UseDebug)
			self::_Debug($message);
		else
			self::_Output($message);
	}
	
	private static function _Output($message)
	{
		if (self::$Cli) self::$Cli->output($message);
	}

	private static function _Debug($message, $type = "")
	{
		eZDebug::witeDebug($message);
	}

}

?>
