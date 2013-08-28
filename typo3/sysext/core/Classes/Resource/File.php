<?php
namespace TYPO3\CMS\Core\Resource;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2011-2013 Ingo Renner <ingo@typo3.org>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * File representation in the file abstraction layer.
 *
 * @author Andreas Wolf <andreas.wolf@ikt-werk.de>
 */
class File extends AbstractFile {

	/**
	 * File indexing status. True, if the file is indexed in the database;
	 * NULL is the default value, this means that the index status is unknown
	 *
	 * @var boolean|NULL
	 */
	protected $indexed = NULL;

	/**
	 * Tells whether to index a file or not.
	 * If yes, the file will be persisted into sys_file.
	 *
	 * @var boolean
	 */
	protected $indexable = TRUE;

	/**
	 * @var array
	 */
	protected $metaDataProperties = array();

	/**
	 * Set to TRUE while this file is being indexed - used to prevent some endless loops
	 *
	 * @var boolean
	 */
	protected $indexingInProgress = FALSE;

	/**
	 * Contains the names of all properties that have been update since the
	 * instantiation of this object
	 *
	 * @var array
	 */
	protected $updatedProperties = array();

	/**
	 * @var \TYPO3\CMS\Core\Resource\Service\IndexerService
	 */
	protected $indexerService = NULL;

	/**
	 * Constructor for a file object. Should normally not be used directly, use
	 * the corresponding factory methods instead.
	 *
	 * @param array $fileData
	 * @param ResourceStorage $storage
	 */
	public function __construct(array $fileData, ResourceStorage $storage) {
		$this->identifier = $fileData['identifier'];
		$this->name = $fileData['name'];
		$this->properties = $fileData;
		$this->storage = $storage;

		if (isset($fileData['uid']) && intval($fileData['uid']) > 0) {
			$this->indexed = TRUE;
			$this->loadMetaData();
		}
	}

	/*******************************
	 * VARIOUS FILE PROPERTY GETTERS
	 *******************************/
	/**
	 * Returns a property value
	 *
	 * @param string $key
	 * @return mixed Property value
	 */
	public function getProperty($key) {
		if ($this->indexed === NULL) {
			$this->loadIndexRecord();
		}
		if (parent::hasProperty($key)) {
			return parent::getProperty($key);
		} else {
			return array_key_exists($key, $this->metaDataProperties) ? $this->metaDataProperties[$key] : NULL;
		}
	}

	/**
	 * Returns the properties of this object.
	 *
	 * @return array
	 */
	public function getProperties() {
		if ($this->indexed === NULL) {
			$this->loadIndexRecord();
		}
		return array_merge(parent::getProperties(), array_diff_key((array)$this->metaDataProperties, parent::getProperties()));
	}

	/**
	 * Returns the MetaData
	 *
	 * @return array|null
	 * @internal
	 */
	public function _getMetaData() {
		return $this->metaDataProperties;
	}

	/******************
	 * CONTENTS RELATED
	 ******************/
	/**
	 * Get the contents of this file
	 *
	 * @return string File contents
	 */
	public function getContents() {
		return $this->getStorage()->getFileContents($this);
	}

	/**
	 * Replace the current file contents with the given string
	 *
	 * @param string $contents The contents to write to the file.
	 * @return File The file object (allows chaining).
	 */
	public function setContents($contents) {
		$this->getStorage()->setFileContents($this, $contents);
		return $this;
	}

	/***********************
	 * INDEX RELATED METHODS
	 ***********************/
	/**
	 * Returns TRUE if this file is indexed
	 *
	 * @return boolean|NULL
	 */
	public function isIndexed() {
		if ($this->indexed === NULL && !$this->indexingInProgress) {
			$this->loadIndexRecord();
		}
		return $this->indexed;
	}

	/**
	 * @param bool $indexIfNotIndexed
	 *
	 * @throws \RuntimeException
	 * @return void
	 */
	protected function loadIndexRecord($indexIfNotIndexed = TRUE) {
		if ($this->indexed !== NULL || !$this->indexable || $this->indexingInProgress) {
			return;
		}
		$this->indexingInProgress = TRUE;

		$indexRecord = $this->getFileIndexRepository()->findOneByCombinedIdentifier($this->getCombinedIdentifier());
		if ($indexRecord === FALSE && $indexIfNotIndexed) {
			// the IndexerService is not used at this place since, its not about additional MetaData anymore
			$indexRecord = $this->getIndexerService()->indexFile($this, FALSE);
			$this->mergeIndexRecord($indexRecord);
			$this->indexed = TRUE;
			$this->loadMetaData();
		} elseif ($indexRecord !== FALSE) {
			$this->mergeIndexRecord($indexRecord);
			$this->indexed = TRUE;
			$this->loadMetaData();
		} else {
			throw new \RuntimeException('Could not load index record for "' . $this->getIdentifier() . '"', 1321288316);
		}
		$this->indexingInProgress = FALSE;
	}

	/**
	 * Loads MetaData from Repository
	 */
	protected function loadMetaData() {
		$this->metaDataProperties = $this->getMetaDataRepository()->findByFile($this);
	}

	/**
	 * Merges the contents of this file's index record into the file properties.
	 *
	 * @param array $recordData The index record as fetched from the database
	 *
	 * @throws \InvalidArgumentException
	 * @return void
	 */
	protected function mergeIndexRecord(array $recordData) {
		if ($this->properties['uid'] != 0) {
			throw new \InvalidArgumentException('uid property is already set. Cannot merge index record.', 1321023156);
		}
		$this->properties = array_merge($recordData, $this->properties);
	}

	/**
	 * Updates the properties of this file, e.g. after re-indexing or moving it.
	 * By default, only properties that exist as a key in the $properties array
	 * are overwritten. If you want to explicitly unset a property, set the
	 * corresponding key to NULL in the array.
	 *
	 * NOTE: This method should not be called from outside the File Abstraction Layer (FAL)!
	 *
	 * @param array $properties
	 * @return void
	 * @internal
	 */
	public function updateProperties(array $properties) {
		// Setting identifier and name to update values; we have to do this
		// here because we might need a new identifier when loading
		// (and thus possibly indexing) a file.
		if (isset($properties['identifier'])) {
			$this->identifier = $properties['identifier'];
		}
		if (isset($properties['name'])) {
			$this->name = $properties['name'];
		}
		if ($this->indexed === NULL && !isset($properties['uid'])) {
			$this->loadIndexRecord();
		}
		if ($this->properties['uid'] != 0 && isset($properties['uid'])) {
			unset($properties['uid']);
		}
		foreach ($properties as $key => $value) {
			if ($this->properties[$key] !== $value) {
				if (!in_array($key, $this->updatedProperties)) {
					$this->updatedProperties[] = $key;
				}
				// TODO check if we should completely remove properties that
				// are set to NULL
				$this->properties[$key] = $value;
			}
		}
		// Updating indexing status
		if (isset($properties['uid']) && intval($properties['uid']) > 0) {
			$this->indexed = TRUE;
			$this->loadMetaData();
		}
		if (array_key_exists('storage', $properties) && in_array('storage', $this->updatedProperties)) {
			$this->storage = ResourceFactory::getInstance()->getStorageObject($properties['storage']);
		}
	}

	/**
	 * Returns the names of all properties that have been updated in this record
	 *
	 * @return array
	 */
	public function getUpdatedProperties() {
		return $this->updatedProperties;
	}

	/****************************************
	 * STORAGE AND MANAGEMENT RELATED METHODS
	 ****************************************/
	/**
	 * Check if a file operation (= action) is allowed for this file
	 *
	 * @param 	string	$action, can be read, write, delete
	 * @return boolean
	 */
	public function checkActionPermission($action) {
		return $this->getStorage()->checkFileActionPermission($action, $this);
	}

	/*****************
	 * SPECIAL METHODS
	 *****************/
	/**
	 * Creates a MD5 hash checksum based on the combined identifier of the file,
	 * the files' mimetype and the systems' encryption key.
	 * used to generate a thumbnail, and this hash is checked if valid
	 *
	 * @todo maybe \TYPO3\CMS\Core\Utility\GeneralUtility::hmac() could be used?
	 * @return string the MD5 hash
	 */
	public function calculateChecksum() {
		return md5($this->getCombinedIdentifier() . '|' . $this->getMimeType() . '|' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
	}

	/**
	 * Returns a modified version of the file.
	 *
	 * @param string $taskType The task type of this processing
	 * @param array $configuration the processing configuration, see manual for that
	 * @return ProcessedFile The processed file
	 */
	public function process($taskType, array $configuration) {
		return $this->getStorage()->processFile($this, $taskType, $configuration);
	}

	/**
	 * Returns an array representation of the file.
	 * (This is used by the generic listing module vidi when displaying file records.)
	 *
	 * @return array Array of main data of the file. Don't rely on all data to be present here, it's just a selection of the most relevant information.
	 */
	public function toArray() {
		$array = array(
			'id' => $this->getCombinedIdentifier(),
			'name' => $this->getName(),
			'extension' => $this->getExtension(),
			'type' => $this->getType(),
			'mimetype' => $this->getMimeType(),
			'size' => $this->getSize(),
			'url' => $this->getPublicUrl(),
			'indexed' => $this->indexed,
			'uid' => $this->getUid(),
			'permissions' => array(
				'read' => $this->checkActionPermission('read'),
				'write' => $this->checkActionPermission('write'),
				'delete' => $this->checkActionPermission('delete')
			),
			'checksum' => $this->calculateChecksum()
		);
		foreach ($this->properties as $key => $value) {
			$array[$key] = $value;
		}
		$stat = $this->getStorage()->getFileInfo($this);
		foreach ($stat as $key => $value) {
			$array[$key] = $value;
		}
		return $array;
	}

	/**
	 * @return boolean
	 */
	public function isIndexable() {
		return $this->indexable;
	}

	/**
	 * @param boolean $indexable
	 */
	public function setIndexable($indexable) {
		$this->indexable = $indexable;
	}

	/**
	 * @return boolean
	 */
	public function isMissing() {
		return (bool) $this->getProperty('missing');
	}

	/**
	 * @param boolean $missing
	 */
	public function setMissing($missing) {
		$this->updateProperties(array('missing' => $missing ? 1 : 0));
	}

	/**
	 * Returns a publicly accessible URL for this file
	 * When file is marked as missing or deleted no url is returned
	 *
	 * WARNING: Access to the file may be restricted by further means, e.g. some
	 * web-based authentication. You have to take care of this yourself.
	 *
	 * @param bool  $relativeToCurrentScript   Determines whether the URL returned should be relative to the current script, in case it is relative at all (only for the LocalDriver)
	 *
	 * @return string
	 */
	public function getPublicUrl($relativeToCurrentScript = FALSE) {
		if ($this->isMissing() || $this->deleted) {
			return FALSE;
		} else {
			return $this->getStorage()->getPublicUrl($this, $relativeToCurrentScript);
		}
	}

	/**
	 * @return \TYPO3\CMS\Core\Resource\Index\MetaDataRepository
	 */
	protected function getMetaDataRepository() {
		return GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\Index\\MetaDataRepository');
	}

	/**
	 * @return \TYPO3\CMS\Core\Resource\Index\FileIndexRepository
	 */
	protected function getFileIndexRepository() {
		return GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\Index\\FileIndexRepository');
	}

	/**
	 * Internal function to retrieve the indexer service,
	 * if it does not exist, an instance will be created
	 *
	 * @return Service\IndexerService
	 */
	protected function getIndexerService() {
		if ($this->indexerService === NULL) {
			$this->indexerService = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\Service\\IndexerService');
		}
		return $this->indexerService;
	}

	/**
	 * @param boolean $indexingState
	 * @internal Only for usage in Indexer
	 */
	public function setIndexingInProgess($indexingState) {
		$this->indexingInProgress = (boolean)$indexingState;
	}

	/**
	 * @param $key
	 * @internal Only for use in Repositories and indexer
	 * @return mixed
	 */
	public function _getPropertyRaw($key) {
		return parent::getProperty($key);
	}
}
