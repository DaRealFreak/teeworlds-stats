<?php

namespace TwStats\Ext;


use TwStats\Core\Frontend\AbstractController;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Ext\Database\AccountRepository;
use TwStats\Ext\Facebook\Facebook;
use TwStats\Ext\FormHandler\FormHandler;

class Account extends AbstractController
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
     * initializing function to replace the constructor function
     */
    public function initialize()
    {
        $this->facebook = GeneralUtility::makeInstance(Facebook::class);
        $this->accountRepository = GeneralUtility::makeInstance(AccountRepository::class);
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

        $user = $this->facebook->getFacebookID(true);

        $formDetails = array("tee", "teetxt", "teemods", "teemaps", "teehours", "teedays",
            "clan", "clantxt", "clanmods", "clanmaps", "clancountries",
            "clanhours", "clandays", "clanplayers");

        if ($user) {
            $page['logged'] = true;

            if (FormHandler::frmsubmitted($formDetails)) {
                // ToDo: display error message
                if (!$err = $this->accountRepository->checkNameAvailability(FormHandler::frmget($formDetails), $user)) {
                    $this->accountRepository->updateAccountDetails(FormHandler::frmget($formDetails), $user);
                    $page['success'] = true;
                } else {
                    $page['success'] = false;
                    $page['errors'] = $err;
                }
            }

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

            if ($account) {
                foreach ($account as $key => $val) {
                    $page[$key] = $val;
                }
            }
        } else {
            $page['logged'] = false;
        }

        $items[] = array('text' => 'About', 'url' => $this->prettyUrl->buildPrettyUri("about"), 'class' => 'icon-info-sign');

        $page['navigation'] = $this->frontendHandler->getTemplateHtml("views/navigation.twig", array("items" => $items));

        $this->frontendHandler->renderTemplate("account.twig", $page);
    }
}
