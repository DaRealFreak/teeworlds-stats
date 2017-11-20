<?php
/**
 * Created by PhpStorm.
 * User: Steffen
 * Date: 13/11/2017
 * Time: 11:27
 */

namespace TwStats\Ext\Facebook;

use Facebook\Exceptions\FacebookResponseException;
use Facebook\Exceptions\FacebookSDKException;
use TwStats\Core\Backend\Database;
use TwStats\Core\Backend\SessionHandler;
use TwStats\Core\General\SingletonInterface;
use TwStats\Core\Utility\GeneralUtility;


class Facebook implements SingletonInterface
{
    /**
     * @var \Facebook\Facebook|null
     */
    protected $facebook = null;

    /**
     * @var Database|null
     */
    protected $databaseConnection = null;
    
    /**
     * Facebook constructor.
     */
    public function __construct()
    {
        $this->facebook = new \Facebook\Facebook(array(
            'app_id' => getenv('TWSTATS_FACEBOOK_APP_ID'),
            'app_secret' => getenv('TWSTATS_FACEBOOK_APP_SECRET'),
        ));
        $this->databaseConnection = GeneralUtility::makeInstance(Database::class);
    }

    /**
     * get the facebook id from the facebook sdk and optionally verify this id
     *
     * @param bool $verify
     * @return int|string
     */
    public function getFacebookID($verify = false)
    {
        // already authenticated before
        if (SessionHandler::hasArgument("facebookUser")) {
            return SessionHandler::getArgument("facebookUser");
        }

        // get the javascript helper
        $jsHelper = $this->facebook->getJavaScriptHelper();

        // get the signed request after the login
        $sr = $jsHelper->getSignedRequest();

        // get the user ID if signed request exists
        $user = $sr ? $sr->getUserId() : 0;

        if ($user && $verify) {
            try {
                // get the access token
                $accessToken = $jsHelper->getAccessToken();
                // returns a `Facebook\FacebookResponse` object
                $this->facebook->get('/me?fields=id,name', $accessToken);
                // save the user for future requests
                $_SESSION['facebookUser'] = $user;
            } catch (FacebookResponseException $e) {
                // echo 'Graph returned an error: ' . $e->getMessage();
                $user = 0;
            } catch (FacebookSDKException $e) {
                // echo 'Facebook SDK returned an error: ' . $e->getMessage();
                $user = 0;
            }
        }

        /*
         * ToDo: why was this in the facebook sdk, can I remove it from the facebook class?
        if (!$user) {
            $formDetails = array("tee", "teetxt", "teemods", "teemaps", "teehours", "teedays",
                "clan", "clantxt", "clanmods", "clanmaps", "clancountries",
                "clanhours", "clandays", "clanplayers");
            frmremove($formDetails);
        }
        */

        return $user;
    }

    /**
     * get the corresponding account to the facebook id
     *
     * @param int $facebookId
     * @return array
     */
    function getAccountDetails($facebookId)
    {
        $req = $this->databaseConnection->sqlQuery("SELECT * FROM accounts WHERE facebookid = ?", array($facebookId));
        return $this->databaseConnection->sqlFetch($req);
    }

    /**
     * delete session data of logged in user
     */
    public function logout()
    {
        SessionHandler::removeArgument("facebookUser");
    }

}