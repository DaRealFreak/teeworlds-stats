<?php

namespace TwStats\Ext;


use TwStats\Core\Frontend\AbstractController;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Ext\Database\StatRepository;
use TwStats\Ext\Facebook\Facebook;

class General extends AbstractController
{

    /**
     * @var Facebook|null
     */
    protected $facebook = null;

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
        $this->statRepository = GeneralUtility::makeInstance(StatRepository::class);
    }

    /**
     * Starting point
     * ToDo: Keeping track of -> link to list of tracked elements
     *
     * @return void
     */
    public function run()
    {
        // Stats
        $mods = $this->statRepository->getglobalhisto("mod", 13);
        list($modNames, $modValues, $modHighestValue) = $this->extractChartValues($mods);
        $page['modNames'] = $modNames;
        $page['modValues'] = $modValues;
        $page['modHighestValue'] = $modHighestValue;

        $countries = $this->statRepository->getglobalhisto("country", 13);
        list($countryNames, $countryValues, $countryHighestValue) = $this->extractChartValues($countries);
        $page['countryNames'] = $countryNames;
        $page['countryValues'] = $countryValues;
        $page['countryHighestValue'] = $countryHighestValue;

        $page = array_merge($page, $this->statRepository->generalCounts());

        $this->frontendHandler->renderTemplate("general.twig", $page);
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