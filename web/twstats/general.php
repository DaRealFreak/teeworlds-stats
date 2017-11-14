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
     *
     * @return void
     */
    public function run()
    {
        $items = array(
            array('text' => 'Game statistics',
                'url' => $this->prettyUrl->buildPrettyUri("general"),
                'class' => 'icon-globe'),
            array('text' => 'Search',
                'url' => $this->prettyUrl->buildPrettyUri(""),
                'class' => 'icon-search')
        );

        $user = $this->facebook->getFacebookID();

        if ($user) {
            $page['logged'] = true;

            $account = $this->facebook->getAccountDetails($user);
            if (!empty($account["tee"])) {
                $items[] = array('text' => $account['tee'],
                    'url' => $this->prettyUrl->buildPrettyUri("tee", array("n" => $account['tee'])),
                    'class' => 'icon-user');
            }
            if (!empty($account["clan"])) {
                $items[] = array('text' => $account['clan'],
                    'url' => $this->prettyUrl->buildPrettyUri("clan", array("n" => $account['clan'])),
                    'class' => 'icon-home');
            }

            $items[] = array('text' => 'Account', 'url' => $this->prettyUrl->buildPrettyUri("account"), 'class' => 'icon-pencil');
        } else {
            $page['logged'] = false;
        }

        $items[] = array('text' => 'About', 'url' => $this->prettyUrl->buildPrettyUri("about"), 'class' => 'icon-info-sign');

        $page['navigation'] = $this->frontendHandler->getTemplateHtml("views/navigation.twig", array("items" => $items));

        // Stats
        $histogramMod = $this->statRepository->getglobalhisto("mod", 13);
        $histogramCountry = $this->statRepository->getglobalhisto("country", 13);

        $page['mods'] = $this->frontendHandler->getTemplateHtml("views/pie.twig",
            array("id" => "piemods",
                "name" => "Most played mods",
                "histogram" => $histogramMod));

        $page['countries'] = $this->frontendHandler->getTemplateHtml("views/pie.twig",
            array("id" => "piecountries",
                "name" => "Most playing countries",
                "histogram" => $histogramCountry));

        $page += $this->statRepository->generalCounts();

        $this->frontendHandler->renderTemplate("general.twig", $page);
    }
}