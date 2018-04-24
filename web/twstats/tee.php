<?php

namespace TwStats\Ext;


use TwStats\Core\Frontend\AbstractController;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Ext\Database\AccountRepository;
use TwStats\Ext\Database\StatRepository;
use TwStats\Ext\Facebook\Facebook;

class Tee extends AbstractController
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
        $tee = $this->requestHandler->getArgument('n');

        if (empty($tee)) {
            GeneralUtility::redirectToUri($this->requestHandler->getFQDN());
        }

        $player = $this->statRepository->getPlayer($tee);

        if (!$player) {
            $payload = [
                'suggestionsTee' => $this->statRepository->getSimilarData("tee", $tee),
                'missingTee' => true
            ];
            GeneralUtility::redirectPostToUri($this->requestHandler->getFQDN(), $payload);
        }

        $tee = $player['tee'];

        $teeDetails = $this->accountRepository->getTeeDetails($tee);
        if (!empty($teeDetails["clan"])) {
            $player['clan'] = $teeDetails["clan"];
        }

        $page['title'] = "Teeworlds statistics - $tee";

        $page['tee'] = $tee;
        if (!empty($player['clan'])) {
            $page['clan'] = $player['clan'];
        }

        if (!empty($player['country'])) {
            $page['country'] = $player['country'];
        }

        $items = [];

        $user = $this->facebook->getFacebookID();
        if ($user) {
            $page['logged'] = true;

            $account = $this->facebook->getAccountDetails($user);
            if (!empty($account["tee"])) {
                $items[] = [
                    'text' => $account['tee'],
                    'url' => $this->prettyUrl->buildPrettyUri("tee", ["n" => $account['tee']]),
                ];
            }
            if (!empty($account["clan"])) {
                $items[] = [
                    'text' => $account['clan'],
                    'url' => $this->prettyUrl->buildPrettyUri("clan", ["n" => $account['clan']]),
                ];
            }
        }

        if (!empty($player['clan'])) {
            $items[] = array(
                'text' => $player['clan'],
                'url' => $this->prettyUrl->buildPrettyUri("clan", array("n" => $player['clan'])),
            );
        }

        $mods = $this->statRepository->gethisto("tee", $tee, "mod");
        list($modNames, $modValues, $modHighestValue) = $this->extractChartValues($mods);
        $page['modNames'] = $modNames;
        $page['modValues'] = $modValues;
        $page['modHighestValue'] = $modHighestValue;

        $maps = $this->statRepository->gethisto("tee", $tee, "map");
        list($mapNames, $mapValues, $mapHighestValue) = $this->extractChartValues($maps);
        $page['mapNames'] = $mapNames;
        $page['mapValues'] = $mapValues;
        $page['mapHighestValue'] = $mapHighestValue;


        $page['hours'] = $this->statRepository->gethours("tee", $tee);
        $page['days'] = $this->statRepository->getdays("tee", $tee);

        $this->frontendHandler->renderTemplate("tee.twig", $page);
    }

    /**
     * parse the return data from the StatRepository to a radar/pie-chart friendly format
     *
     * @param array $inputArray
     * @return array
     */
    private function extractChartValues(array $inputArray) {
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
