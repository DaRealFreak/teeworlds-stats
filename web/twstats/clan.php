<?php

namespace TwStats\Ext;


use TwStats\Core\Frontend\AbstractController;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Ext\Database\AccountRepository;
use TwStats\Ext\Database\StatRepository;
use TwStats\Ext\Facebook\Facebook;
use TwStats\Ext\Youtube\Youtube;

class Clan extends AbstractController
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
        $clan = $this->requestHandler->getArgument('n');

        if (empty($clan)) {
            GeneralUtility::redirectToUri($this->requestHandler->getFQDN());
        }

        $clan = $this->statRepository->getClanName($clan);
        if (!$clan) {
            $payload = [
                'suggestionsClan' => $this->statRepository->getSimilarData("clan", $clan),
                'missingClan' => true
            ];
            GeneralUtility::redirectPostToUri($this->requestHandler->getFQDN(), $payload);
        }

        $items = [];

        $user = $this->facebook->getFacebookID();
        if ($user) {
            $page['logged'] = true;

            $account = $this->facebook->getAccountDetails($user);
            if (!empty($account["tee"])) {
                $items[] = [
                    'text' => $account['tee'],
                    'url' => $this->prettyUrl->buildPrettyUri("tee", ["n" => $account['tee']])
                ];
            }

            if (!empty($account["clan"])) {
                $items[] = [
                    'text' => $account['clan'],
                    'url' => $this->prettyUrl->buildPrettyUri("clan", ["n" => $account['clan']]),
                ];
            }

            $items[] = [
                'text' => 'Account',
                'url' => $this->prettyUrl->buildPrettyUri("account"),
            ];
        }

        /*		SELECTING CLAN INFO TO DISPLAY		*/
        $clanDetails = $this->accountRepository->getClanDetails($clan);


        if (strip_tags($clanDetails["clantxt"]) != "") {
            $page["clantxt"] = Youtube::integrateYoutubeVideos($clanDetails["clantxt"]);
        }

        $page['hours'] = $this->statRepository->gethours("clan", $clan);
        $page['days'] = $this->statRepository->getdays("clan", $clan);

        $countries = $this->statRepository->gethisto("clan", $clan, "country");
        list($countryNames, $countryValues, $countryHighestValue) = $this->extractChartValues($countries);
        $page['countryNames'] = $countryNames;
        $page['countryValues'] = $countryValues;
        $page['countryHighestValue'] = $countryHighestValue;

        $mods = $this->statRepository->gethisto("clan", $clan, "mod");
        list($modNames, $modValues, $modHighestValue) = $this->extractChartValues($mods);
        $page['modNames'] = $modNames;
        $page['modValues'] = $modValues;
        $page['modHighestValue'] = $modHighestValue;

        $maps = $this->statRepository->gethisto("clan", $clan, "map");
        list($mapNames, $mapValues, $mapHighestValue) = $this->extractChartValues($maps);
        $page['mapNames'] = $mapNames;
        $page['mapValues'] = $mapValues;
        $page['mapHighestValue'] = $mapHighestValue;

        $players = $this->statRepository->getClanPlayers($clan);
        foreach ($players as &$player) {
            $player['url'] = $this->prettyUrl->buildPrettyUri("tee", array("n" => $player['name']));
        }

        $page['players'] = $players;

        $page['title'] = "$clan statistics on Teeworlds";
        $page['clan'] = $clan;

        $this->frontendHandler->renderTemplate("clan.twig", $page);
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