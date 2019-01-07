<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Symfony\Component\HttpFoundation\Response;

/**
 * Command scheduler controller.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class FrontendCron extends Frontend
{

	/**
	 * Initialize the object (do not remove)
	 */
	public function __construct()
	{
		parent::__construct();

		// See #4099
		if (!\defined('BE_USER_LOGGED_IN'))
		{
			\define('BE_USER_LOGGED_IN', false);
		}

		if (!\defined('FE_USER_LOGGED_IN'))
		{
			\define('FE_USER_LOGGED_IN', false);
		}
	}

	/**
	 * Run the controller
	 *
	 * @return Response
	 */
	public function run()
	{
		$objResponse = new Response('', 204);

		// Do not run if there is POST data or the last execution was less than a minute ago
		if (!empty($_POST) || $this->hasToWait())
		{
			return $objResponse;
		}

		$arrLock = array();
		$arrIntervals = array('monthly', 'weekly', 'daily', 'hourly', 'minutely');

		// Store the current timestamps
		$arrCurrent = array
		(
			'monthly'  => date('Ym'),
			'weekly'   => date('YW'),
			'daily'    => date('Ymd'),
			'hourly'   => date('YmdH'),
			'minutely' => date('YmdHi')
		);

		// Get the timestamps from tl_cron
		$objLock = $this->Database->query("SELECT * FROM tl_cron WHERE name !='lastrun'");

		while ($objLock->next())
		{
			$arrLock[$objLock->name] = $objLock->value;
		}

		// Create the database entries
		foreach ($arrIntervals as $strInterval)
		{
			if (!isset($arrLock[$strInterval]))
			{
				$arrLock[$strInterval] = 0;
				$this->Database->query("INSERT INTO tl_cron (name, value) VALUES ('$strInterval', 0)");
			}
		}

		// Load the default language file (see #8719)
		System::loadLanguageFile('default');

		// Run the jobs
		foreach ($arrIntervals as $strInterval)
		{
			$intCurrent = $arrCurrent[$strInterval];

			// Skip empty intervals and jobs that have been executed already
			if (empty($GLOBALS['TL_CRON'][$strInterval]) || $arrLock[$strInterval] == $intCurrent)
			{
				continue;
			}

			// Update the database before the jobs are executed, in case one of them fails
			$this->Database->query("UPDATE tl_cron SET value=$intCurrent WHERE name='$strInterval'");

			// Add a log entry if in debug mode (see #4729)
			if (Config::get('debugMode'))
			{
				$this->log('Running the ' . $strInterval . ' cron jobs', __METHOD__, TL_CRON);
			}

			foreach ($GLOBALS['TL_CRON'][$strInterval] as $callback)
			{
				$this->import($callback[0]);
				$this->{$callback[0]}->{$callback[1]}();
			}

			// Add a log entry if in debug mode (see #4729)
			if (Config::get('debugMode'))
			{
				$this->log(ucfirst($strInterval) . ' cron jobs complete', __METHOD__, TL_CRON);
			}
		}

		return $objResponse;
	}

	/**
	 * Check whether the last script execution was less than a minute ago
	 *
	 * @return boolean
	 */
	protected function hasToWait()
	{
		$return = true;

		// Get the timestamp without seconds (see #5775)
		$time = strtotime(date('Y-m-d H:i'));

		// Lock the table
		$this->Database->lockTables(array('tl_cron'=>'WRITE'));

		// Get the last execution date
		$objCron = $this->Database->prepare("SELECT * FROM tl_cron WHERE name='lastrun'")
								  ->limit(1)
								  ->execute();

		// Add the cron entry
		if ($objCron->numRows < 1)
		{
			$this->Database->query("INSERT INTO tl_cron (name, value) VALUES ('lastrun', $time)");
			$return = false;
		}

		// Check the last execution time
		elseif ($objCron->value <= ($time - $this->getCronTimeout()))
		{
			$this->Database->query("UPDATE tl_cron SET value=$time WHERE name='lastrun'");
			$return = false;
		}

		$this->Database->unlockTables();

		return $return;
	}
}

class_alias(FrontendCron::class, 'FrontendCron');
