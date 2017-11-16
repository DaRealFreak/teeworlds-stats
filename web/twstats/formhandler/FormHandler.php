<?php

namespace TwStats\Ext\FormHandler;


use TwStats\Core\General\SingletonInterface;

/*
 * ToDo: handle forms with post requests not session
 */
class FormHandler implements SingletonInterface
{
    /**
     * Check whether a form has been submitted
     *
     * @param $frm
     * @return bool
     */
    public static function frmsubmitted($frm)
    {
        foreach ($frm as $field) {
            if (!isset($_GET[$field]) && !isset($_POST[$field])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Saves a form in the session
     *
     * @param $frm
     */
    public static function frmsave($frm)
    {
        $form = array();
        foreach ($frm as $field) {
            if (isset($_GET[$field])) {
                $form[$field] = $_GET[$field];
            }
            if (isset($_POST[$field])) {
                $form[$field] = $_POST[$field];
            }
        }
        if (!empty($form)) {
            $_SESSION[self::frmname($frm)] = $form;
        }
    }

    /**
     * Returns a form saved in the session
     *
     * @param $frm
     * @return array
     */
    public static function frmrestore($frm)
    {
        $name = self::frmname($frm);
        return isset($_SESSION[$name]) ? $_SESSION[$name] : array();
    }

    /**
     * Removes a form saved in the session
     *
     * @param $frm
     */
    public static function frmremove($frm)
    {
        $name = self::frmname($frm);
        unset($_SESSION[$name]);
    }

    /**
     * Returns the submitted form
     *
     * @param $frm
     * @return array
     */
    public static function frmget($frm)
    {
        $res = array();
        foreach ($frm as $field) {
            if (isset($_GET[$field])) {
                $res[$field] = $_GET[$field];
            }
            if (isset($_POST[$field])) {
                $res[$field] = $_POST[$field];
            }
        }
        return $res;
    }

    /**
     * Returns the name of the form as stored in the session
     *
     * @param $frm
     * @return string
     */
    public static function frmname($frm)
    {
        $name = "";
        foreach ($frm as $field) {
            $name = $name . " " . $field;
        }
        return $name;
    }

    /**
     * Removes the given fields if empty
     *
     * @param $form
     * @param $fields
     * @return
     */
    public static function frmtidy($form, $fields)
    {
        $res = $form;
        foreach ($fields as $field) {
            if (empty($res[$field])) {
                unset($res[$field]);
            }
        }
        return $res;
    }
}