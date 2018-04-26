<?php

namespace TwStats\Ext;


use TwStats\Core\Frontend\AbstractController;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Ext\Database\AccountRepository;
use TwStats\Ext\Database\StatRepository;
use TwStats\Ext\Facebook\Facebook;

class Server extends AbstractController
{

    /**
     * @var Facebook
     */
    protected $facebook = null;

    /**
     * @var AccountRepository
     */
    protected $accountRepository = null;

    /**
     * @var StatRepository
     */
    protected $statRepository = null;

    /**
     * initializing function to replace the constructor function
     */
    public function initialize()
    {
        $this->facebook = GeneralUtility::makeInstance(Facebook::class);
        $this->accountRepository = GeneralUtility::makeInstance(AccountRepository::class);
        $this->statRepository = GeneralUtility::makeInstance(StatRepository::class);
    }

    /**
     * Starting point
     *
     * @return void
     */
    public function run()
    {
        $server = $this->requestHandler->getArgument('n');
        if (empty($server)) {
            GeneralUtility::redirectToUri($this->requestHandler->getFQDN());
        }

        $name = $this->statRepository->getServerName($server);

        if (!$name) {
            $payload = [
                'suggestionsServer' => $this->statRepository->getSimilarData("server", $server),
                'missingServer' => true
            ];
            GeneralUtility::redirectPostToUri($this->requestHandler->getFQDN(), $payload);
        }
        $server = $name;

        $page['server'] = [
            'name' => $server,
            'url' => $this->prettyUrl->buildPrettyUri("server", ["n" => $server]),
        ];

        $maps = $this->statRepository->gethisto("server", $server, "map");
        list($mapNames, $mapValues, $mapHighestValue) = $this->extractChartValues($maps);
        $page['mapNames'] = $mapNames;
        $page['mapValues'] = $mapValues;
        $page['mapHighestValue'] = $mapHighestValue;

        $countries = $this->statRepository->gethisto("server", $server, "country");
        list($countryNames, $countryValues, $countryHighestValue) = $this->extractChartValues($countries);
        $page['countryNames'] = $countryNames;
        $page['countryValues'] = $countryValues;
        $page['countryHighestValue'] = $countryHighestValue;

        $page['hours'] = $this->statRepository->gethours("server", $server);
        $page['days'] = $this->statRepository->getdays("server", $server);

        $players = $this->statRepository->getServerPlayers($server);
        foreach ($players as &$player) {
            $player['url'] = $this->prettyUrl->buildPrettyUri("tee", array("n" => $player['name']));
        }

        $page['title'] = "$server statistics on Teeworlds";

        $page['players'] = $players;

        $this->frontendHandler->renderTemplate("server.twig", $page);
    }

    /**
     * parse the return data from the StatRepository to a radar/pie-chart friendly format
     *
     * @param array $inputArray
     * @return array
     */
    private function extractChartValues(array $inputArray)
    {
        $names = [];
        $values = [];
        $highestValue = 0;
        foreach ($inputArray as $inputData) {
            if ($inputData[1] > $highestValue) {
                $highestValue = $inputData[1];
            }
            $names[] = $inputData[0];
            $values[] = $inputData[1];
        }
        return [$names, $values, $highestValue];
    }
}
