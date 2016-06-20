<?php
/**
 * OAI-PMH Harvest Tool
 *
 * PHP version 5
 *
 * Copyright (c) Demian Katz 2010.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind
 * @package  Harvest_Tools
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/indexing:oai-pmh Wiki
 */
namespace VuFindHarvest;
use Zend\Http\Client;

/**
 * OAI Class
 *
 * This class harvests records via OAI-PMH using settings from oai.ini.
 *
 * @category VuFind
 * @package  Harvest_Tools
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/indexing:oai-pmh Wiki
 */
class OaiHarvester
{
    use WriterTrait;

    /**
     * Record writer
     *
     * @var OaiRecordWriter
     */
    protected $writer;

    /**
     * Low-level OAI-PMH communicator
     *
     * @var OaiCommunicator
     */
    protected $communicator;

    /**
     * Target set(s) to harvest (null for all records)
     *
     * @var string|array
     */
    protected $set = null;

    /**
     * Metadata type to harvest
     *
     * @var string
     */
    protected $metadataPrefix = 'oai_dc';

    /**
     * Directory for storing harvested files
     *
     * @var string
     */
    protected $basePath;

    /**
     * File for tracking last harvest date
     *
     * @var string
     */
    protected $lastHarvestFile;

    /**
     * File for tracking last harvest state (for continuing interrupted
     * connection).
     *
     * @var string
     */
    protected $lastStateFile;

    /**
     * Harvest end date (null for no specific end)
     *
     * @var string
     */
    protected $harvestEndDate;

    /**
     * Harvest start date (null for no specific start)
     *
     * @var string
     */
    protected $startDate = null;

    /**
     * Date granularity ('auto' to autodetect)
     *
     * @var string
     */
    protected $granularity = 'auto';

    /**
     * Constructor.
     *
     * @param string $target      Name of source being harvested (used as directory
     * name for storing harvested data inside $harvestRoot)
     * @param string $harvestRoot Root directory containing harvested data.
     * @param array  $settings    OAI-PMH settings from oai.ini.
     * @param Client $client      HTTP client
     * @param string $from        Harvest start date (omit to use last_harvest.txt)
     * @param string $until       Harvest end date (optional)
     * @param bool   $silent      Should we suppress output?
     */
    public function __construct($target, $harvestRoot, $settings, Client $client,
        $from = null, $until = null, $silent = true
    ) {
        // Store silence setting (configure WriterTrait):
        $this->isSilent($silent);

        // Don't time out during harvest!!
        set_time_limit(0);

        // Set up base directory for harvested files:
        $this->setBasePath($harvestRoot, $target);

        // Check if there is a file containing a start date:
        $this->lastHarvestFile = $this->basePath . 'last_harvest.txt';
        $this->lastStateFile = $this->basePath . 'last_state.txt';

        // Set up start/end dates:
        $this->setStartDate(empty($from) ? $this->loadLastHarvestedDate() : $from);
        $this->setEndDate($until);

        // Save configuration:
        $this->setConfig($target, $settings);

        // Build communicator and response writer:
        $rp = new SimpleXmlResponseProcessor($this->basePath, $settings);
        $this->communicator = new OaiCommunicator($client, $settings, $rp, $silent);
        $this->writer = $this->constructWriter($settings);

        // Autoload granularity if necessary:
        if ($this->granularity == 'auto') {
            $this->loadGranularity();
        }
    }

    /**
     * Support method for constructor -- build the writer support object.
     *
     * @param array $settings OAI-PMH settings from oai.ini.
     *
     * @return OaiRecordWriter
     */
    protected function constructWriter($settings)
    {
        // Build the formatter:
        $formatter = new OaiRecordXmlFormatter($settings);

        // Load set names if we're going to need them:
        if ($formatter->needsSetNames()) {
            $formatter->setSetNames($this->loadSetNames());
        }

        // Build the writer:
        return new OaiRecordWriter($this->basePath, $formatter, $settings);
    }

    /**
     * Set an end date for the harvest (only harvest records BEFORE this date).
     *
     * @param string $date End date (YYYY-MM-DD format).
     *
     * @return void
     */
    public function setEndDate($date)
    {
        $this->harvestEndDate = $date;
    }
    /**
     * Set a start date for the harvest (only harvest records AFTER this date).
     *
     * @param string $date Start date (YYYY-MM-DD format).
     *
     * @return void
     */
    public function setStartDate($date)
    {
        $this->startDate = $date;
    }

    /**
     * Harvest all available documents.
     *
     * @return void
     */
    public function launch()
    {
        // Normalize sets setting to an array:
        $sets = (array)$this->set;
        if (empty($sets)) {
            $sets = [null];
        }

        // Load last state, if applicable (used to recover from server failure).
        if (file_exists($this->lastStateFile)) {
            $this->write("Found {$this->lastStateFile}; attempting to resume.\n");
            list($resumeSet, $resumeToken, $this->startDate)
                = explode("\t", file_get_contents($this->lastStateFile));
        }

        // Loop through all of the selected sets:
        foreach ($sets as $set) {
            // If we're resuming and there are multiple sets, find the right one.
            if (isset($resumeToken) && $resumeSet != $set) {
                continue;
            }

            // If we have a token to resume from, pick up there now...
            if (isset($resumeToken)) {
                $token = $resumeToken;
                unset($resumeToken);
            } else {
                // ...otherwise, start harvesting at the requested date:
                $token = $this->getRecordsByDate(
                    $this->startDate, $set, $this->harvestEndDate
                );
            }

            // Keep harvesting as long as a resumption token is provided:
            while ($token !== false) {
                // Save current state in case we need to resume later:
                file_put_contents(
                    $this->lastStateFile, "$set\t$token\t{$this->startDate}"
                );
                $token = $this->getRecordsByToken($token);
            }
        }

        // If we made it this far, all was successful, so we should clean up
        // the "last state" file.
        if (file_exists($this->lastStateFile)) {
            unlink($this->lastStateFile);
        }
    }

    /**
     * Set up directory structure for harvesting (support method for constructor).
     *
     * @param string $harvestRoot Root directory containing harvested data.
     * @param string $target      The OAI-PMH target directory to create inside
     * $harvestRoot.
     *
     * @return void
     */
    protected function setBasePath($harvestRoot, $target)
    {
        // Build the full harvest path:
        $this->basePath = rtrim($harvestRoot, '/') . '/' . rtrim($target, '/') . '/';

        // Create the directory if it does not already exist:
        if (!is_dir($this->basePath)) {
            if (!mkdir($this->basePath)) {
                throw new \Exception(
                    "Problem creating directory {$this->basePath}."
                );
            }
        }
    }

    /**
     * Retrieve the date from the "last harvested" file and use it as our start
     * date if it is available.
     *
     * @return string
     */
    protected function loadLastHarvestedDate()
    {
        return (file_exists($this->lastHarvestFile))
            ? trim(current(file($this->lastHarvestFile))) : null;
    }

    /**
     * Save a date to the "last harvested" file.
     *
     * @param string $date Date to save.
     *
     * @return void
     */
    protected function saveLastHarvestedDate($date)
    {
        file_put_contents($this->lastHarvestFile, $date);
    }

    /**
     * Make an OAI-PMH request.  Die if there is an error; return a SimpleXML object
     * on success.
     *
     * @param string $verb   OAI-PMH verb to execute.
     * @param array  $params GET parameters for ListRecords method.
     *
     * @return object        SimpleXML-formatted response.
     */
    protected function sendRequest($verb, $params = [])
    {
        $response = $this->communicator->request($verb, $params);
        $this->checkResponseForErrors($response);
        return $response;
    }

    /**
     * Load date granularity from the server.
     *
     * @return void
     */
    protected function loadGranularity()
    {
        $this->write("Autodetecting date granularity... ");
        $response = $this->sendRequest('Identify');
        $this->granularity = (string)$response->Identify->granularity;
        $this->writeLine("found {$this->granularity}.");
    }

    /**
     * Load set list from the server.
     *
     * @return array
     */
    protected function loadSetNames()
    {
        $this->write("Loading set list... ");

        // On the first pass through the following loop, we want to get the
        // first page of sets without using a resumption token:
        $params = [];

        $setNames = [];

        // Grab set information until we have it all (at which point we will
        // break out of this otherwise-infinite loop):
        do {
            // Process current page of results:
            $response = $this->sendRequest('ListSets', $params);
            if (isset($response->ListSets->set)) {
                foreach ($response->ListSets->set as $current) {
                    $spec = (string)$current->setSpec;
                    $name = (string)$current->setName;
                    if (!empty($spec)) {
                        $setNames[$spec] = $name;
                    }
                }
            }

            // Is there a resumption token?  If so, continue looping; if not,
            // we're done!
            $params['resumptionToken']
                = !empty($response->ListSets->resumptionToken)
                ? (string)$response->ListSets->resumptionToken : '';
        } while (!empty($params['resumptionToken']));
        $this->writeLine("found " . count($setNames));
        return $setNames;
    }

    /**
     * Check an OAI-PMH response for errors that need to be handled.
     *
     * @param object $result OAI-PMH response (SimpleXML object)
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function checkResponseForErrors($result)
    {
        // Detect errors and die if one is found:
        if ($result->error) {
            $attribs = $result->error->attributes();

            // If this is a bad resumption token error and we're trying to
            // restore a prior state, we should clean up.
            if ($attribs['code'] == 'badResumptionToken'
                && file_exists($this->lastStateFile)
            ) {
                unlink($this->lastStateFile);
                throw new \Exception(
                    "Token expired; removing last_state.txt. Please restart harvest."
                );
            }
            throw new \Exception(
                "OAI-PMH error -- code: {$attribs['code']}, " .
                "value: {$result->error}"
            );
        }
    }

    /**
     * Harvest records using OAI-PMH.
     *
     * @param array $params GET parameters for ListRecords method.
     *
     * @return mixed        Resumption token if provided, false if finished
     */
    protected function getRecords($params)
    {
        // Make the OAI-PMH request:
        $response = $this->sendRequest('ListRecords', $params);

        // Save the records from the response:
        if ($response->ListRecords->record) {
            $this->writeLine(
                'Processing ' . count($response->ListRecords->record) . " records..."
            );
            $endDate = $this->writer->write($response->ListRecords->record);
        }

        // If we have a resumption token, keep going; otherwise, we're done -- save
        // the end date.
        if (isset($response->ListRecords->resumptionToken)
            && !empty($response->ListRecords->resumptionToken)
        ) {
            return $response->ListRecords->resumptionToken;
        } else if (isset($endDate) && $endDate > 0) {
            $dateFormat = ($this->granularity == 'YYYY-MM-DD') ?
                'Y-m-d' : 'Y-m-d\TH:i:s\Z';
            $this->saveLastHarvestedDate(date($dateFormat, $endDate));
        }
        return false;
    }

    /**
     * Harvest records via OAI-PMH using date and set.
     *
     * @param string $from  Harvest start date (null for no specific start).
     * @param string $set   Set to harvest (null for all records).
     * @param string $until Harvest end date (null for no specific end).
     *
     * @return mixed        Resumption token if provided, false if finished
     */
    protected function getRecordsByDate($from = null, $set = null, $until = null)
    {
        $params = ['metadataPrefix' => $this->metadataPrefix];
        if (!empty($from)) {
            $params['from'] = $from;
        }
        if (!empty($set)) {
            $params['set'] = $set;
        }
        if (!empty($until)) {
            $params['until'] = $until;
        }
        return $this->getRecords($params);
    }

    /**
     * Harvest records via OAI-PMH using resumption token.
     *
     * @param string $token Resumption token.
     *
     * @return mixed        Resumption token if provided, false if finished
     */
    protected function getRecordsByToken($token)
    {
        return $this->getRecords(['resumptionToken' => (string)$token]);
    }

    /**
     * Set configuration (support method for constructor).
     *
     * @param string $target   Target directory for harvest.
     * @param array  $settings Configuration
     *
     * @return void
     */
    protected function setConfig($target, $settings)
    {
        if (empty($settings['url'])) {
            throw new \Exception("Missing base URL for {$target}.");
        }

        // Settings that may be mapped directly from $settings to class properties:
        $mappableSettings = ['set', 'metadataPrefix'];
        foreach ($mappableSettings as $current) {
            if (isset($settings[$current])) {
                $this->$current = $settings[$current];
            }
        }

        // Special case: $settings value does not match property value (for
        // readability):
        if (isset($settings['dateGranularity'])) {
            $this->granularity = $settings['dateGranularity'];
        }
    }
}
