<?php

namespace TwStats\Ext;


use TwStats\Core\Frontend\AbstractController;
use TwStats\Core\Utility\GeneralUtility;
use TwStats\Ext\Facebook\Facebook;
use TwStats\Ext\FormHandler\FormHandler;

class Account extends AbstractController
{

    /**
     * @var Facebook|null
     */
    protected $facebook = null;

    /**
     * initializing function to replace the constructor function
     */
    public function initialize()
    {
        $this->facebook = GeneralUtility::makeInstance(Facebook::class);
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
                var_dump($formDetails);
                if (!$err = checkNameAvailability(frmget($formDetails), $user)) {
                    updateAccountDetails(frmget($formDetails), $user);
                    $_SESSION['success'] = true;
                } else {
                    $_SESSION['success'] = false;
                    $_SESSION['errors'] = $err;
                }

                redirect("index.php?p=account");
            }

            if (!empty($_SESSION['success'])) {
                $page['success'] = $_SESSION['success'];
                unset($_SESSION['success']);
            }
            if (!empty($_SESSION['errors'])) {
                $page['errors'] = $_SESSION['errors'];
                unset($_SESSION['errors']);
            }

            $account = $this->facebook->getAccountDetails($user);
            if (!empty($account["tee"]))
                $items[] = array('text' => $account['tee'],
                    'url' => $this->prettyUrl->buildPrettyUri("tee", array("n" => $account['tee'])),
                    'class' => 'icon-user');
            if (!empty($account["clan"]))
                $items[] = array('text' => $account['clan'],
                    'url' => $this->prettyUrl->buildPrettyUri("clan", array("n" => $account['clan'])),
                    'class' => 'icon-home');

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

        echo $this->frontendHandler->renderTemplate("account.twig", $page);
    }

    public function old_run()
    {
        $items = array(
            array('text' => 'Game statistics',
                'url' => myurl("general"),
                'class' => 'icon-globe'),
            array('text' => 'Search',
                'url' => myurl(""),
                'class' => 'icon-search')
        );

        $user = getFacebookID(true);

        $formDetails = array("tee", "teetxt", "teemods", "teemaps", "teehours", "teedays",
            "clan", "clantxt", "clanmods", "clanmaps", "clancountries",
            "clanhours", "clandays", "clanplayers");

        if ($user) {
            $page['logged'] = true;

            if (frmsubmitted($formDetails)) {
                var_dump($formDetails);
                if (!$err = checkNameAvailability(frmget($formDetails), $user)) {
                    updateAccountDetails(frmget($formDetails), $user);
                    $_SESSION['success'] = true;
                } else {
                    $_SESSION['success'] = false;
                    $_SESSION['errors'] = $err;
                }

                redirect("index.php?p=account");
            }

            if (!empty($_SESSION['success'])) {
                $page['success'] = $_SESSION['success'];
                unset($_SESSION['success']);
            }
            if (!empty($_SESSION['errors'])) {
                $page['errors'] = $_SESSION['errors'];
                unset($_SESSION['errors']);
            }

            $account = getAccountDetails($user);
            if (!empty($account["tee"]))
                $items[] = array('text' => $account['tee'],
                    'url' => myurl("tee", array("n" => $account['tee'])),
                    'class' => 'icon-user');
            if (!empty($account["clan"]))
                $items[] = array('text' => $account['clan'],
                    'url' => myurl("clan", array("n" => $account['clan'])),
                    'class' => 'icon-home');

            $items[] = array('text' => 'Account', 'url' => myurl("account"), 'class' => 'icon-pencil');

            if ($account)
                foreach ($account as $key => $val)
                    $page[$key] = $val;
        } else
            $page['logged'] = false;

        $items[] = array('text' => 'About', 'url' => myurl("about"), 'class' => 'icon-info-sign');

        $page['navigation'] = $twig->render("views/navigation.twig", array("items" => $items));

        echo trender("templates/account.twig", $page);
    }
}
