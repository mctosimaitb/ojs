<?php

/**
 * @file plugins/importexport/datacite/DataciteInfoSender.php
 *
 * Copyright (c) 2013-2016 Simon Fraser University Library
 * Copyright (c) 2003-2016 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class DataciteInfoSender
 * @ingroup plugins_importexport_datacite
 *
 * @brief Scheduled task to register DOIs to the DataCite server.
 */

import('lib.pkp.classes.scheduledTask.ScheduledTask');


class DataciteInfoSender extends ScheduledTask {
	/** @var $_plugin DataciteExportPlugin */
	var $_plugin;

	/**
	 * Constructor.
	 * @param $argv array task arguments
	 */
	function DataciteInfoSender($args) {
		PluginRegistry::loadCategory('importexport');
		$plugin =& PluginRegistry::getPlugin('importexport', 'DataciteExportPlugin'); /* @var $plugin DataciteExportPlugin */
		$this->_plugin =& $plugin;

		if (is_a($plugin, 'DataciteExportPlugin')) {
			$plugin->addLocaleData();
		}

		parent::ScheduledTask($args);
	}

	/**
	 * @see ScheduledTask::getName()
	 */
	function getName() {
		return __('plugins.importexport.datacite.senderTask.name');
	}

	/**
	 * @see ScheduledTask::executeActions()
	 */
	function executeActions() {
		if (!$this->_plugin) return false;

		$plugin = $this->_plugin;

		$journals = $this->_getJournals();
		$request =& Application::getRequest();

		foreach ($journals as $journal) {
			$unregisteredIssues = $plugin->_getUnregisteredIssues($journal);
			$unregisteredArticles = $plugin->_getUnregisteredArticles($journal);
			$unregisteredGalleys = $plugin->_getUnregisteredGalleys($journal);
			$unregisteredSuppFiles = $plugin->_getUnregisteredSuppFiles($journal);
			$errors = array();

			$unregisteredIssueIds = array();
			foreach ($unregisteredIssues as $issue) {
				if ($plugin->canBeExported($issue, $errors)) {
					$unregisteredIssueIds[] = $issue->getId();
				}
			}
			$unregisteredArticlesIds = array();
			foreach ($unregisteredArticles as $articleData) {
				$article = $articleData['article'];
				if (is_a($article, 'PublishedArticle') && $plugin->canBeExported($article, $errors)) {
					$unregisteredArticlesIds[] = $article->getId();
				}
			}
			$unregisteredGalleyIds = array();
			foreach ($unregisteredGalleys as $galleyData) {
				$galley = $galleyData['galley'];
				if ($plugin->canBeExported($galley, $errors)) {
					$unregisteredGalleyIds[] = $galley->getId();
				}
			}
			$unregisteredSuppFileIds = array();
			foreach ($unregisteredSuppFiles as $suppFileData) {
				$suppFile = $suppFileData['suppFile'];
				if ($plugin->canBeExported($suppFile, $errors)) {
					$unregisteredSuppFileIds[$suppFile->getId()] = $suppFile->getId();
				}
			}

			// If there are unregistered DOIs and we want automatic deposits
			$exportSpec = array();
			$register = false;
			if (count($unregisteredIssueIds)) {
				$exportSpec[DOI_EXPORT_ISSUES] = $unregisteredIssueIds;
				$register = true;
			}
			if (count($unregisteredArticles)) {
				$exportSpec[DOI_EXPORT_ARTICLES] = $unregisteredArticlesIds;
				$register = true;
			}
			if (count($unregisteredGalleyIds)) {
				$exportSpec[DOI_EXPORT_GALLEYS] = $unregisteredGalleyIds;
				$register = true;
			}
			if (count($unregisteredSuppFileIds)) {
				$exportSpec[DOI_EXPORT_SUPPFILES] = $unregisteredSuppFileIds;
				$register = true;
			}

			if ($register) {
				$plugin->registerObjects($request, $exportSpec, $journal);
			}
		}
		return true;
	}

	/**
	 * Get all journals that meet the requirements to have
	 * their articles DOIs sent to DataCite.
	 * @return array
	 */
	function _getJournals() {
		$plugin =& $this->_plugin;
		$journalDao =& DAORegistry::getDAO('JournalDAO'); /* @var $journalDao JournalDAO */
		$journalFactory =& $journalDao->getJournals(true);

		$journals = array();
		while($journal =& $journalFactory->next()) {
			$journalId = $journal->getId();
			if (!$plugin->getSetting($journalId, 'username') || !$plugin->getSetting($journalId, 'password') || !$plugin->getSetting($journalId, 'automaticRegistration')) continue;

			$doiPrefix = null;
			$pubIdPlugins =& PluginRegistry::loadCategory('pubIds', true, $journalId);
			if (isset($pubIdPlugins['DOIPubIdPlugin'])) {
				$doiPubIdPlugin =& $pubIdPlugins['DOIPubIdPlugin'];
				$doiPrefix = $doiPubIdPlugin->getSetting($journalId, 'doiPrefix');
			}

			if ($doiPrefix) {
				$journals[] =& $journal;
			} else {
				$this->addExecutionLogEntry(
					__('plugins.importexport.common.senderTask.warning.noDOIprefix', array('path' => $journal->getPath())),
					SCHEDULED_TASK_MESSAGE_TYPE_WARNING
				);
			}
			unset($journal);
		}

		return $journals;
	}
}
?>
