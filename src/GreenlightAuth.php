<?php
/**
 * GreenLight Auth plugin for Craft CMS 3.x
 *
 * Bespoke authentication for GreenLight users
 *
 * @link      autumndev.co.uk
 * @copyright Copyright (c) 2019 Scott Jones
 */

namespace autumndev\greenlightauth;


use Craft;
use craft\base\Plugin;
use craft\elements\User;
use craft\services\Elements;
use craft\services\Users;
use craft\events\UserAssignGroupEvent;
use craft\records\User as UserRecord;
use yii\base\Application;
use yii\base\Event;
use craft\web\User as WebUser;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://craftcms.com/docs/plugins/introduction
 *
 * @author    Scott Jones
 * @package   GreenlightAuth
 * @since     1
 *
 */
class GreenlightAuth extends Plugin
{
    const WHITELABEL    = "greenlight";
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * GreenlightAuth::$plugin
     *
     * @var GreenlightAuth
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public $schemaVersion = '1';

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * GreenlightAuth::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Register Events
        // Event::on(
        //     Elements::class, 
        //     Elements::EVENT_AFTER_SAVE_ELEMENT, 
        //     [$this, 'onCustomRegister']
        // );
        Event::on(
            Users::class, 
            Users::EVENT_AFTER_ASSIGN_USER_TO_DEFAULT_GROUP, 
            [$this, 'onCustomRegister']
        );
        Event::on(
            WebUser::class, 
            WebUser::EVENT_AFTER_LOGIN , 
            [$this, 'onCustomAuth']
        );
        Event::on(
            Application::class, 
            Application::EVENT_BEFORE_REQUEST, 
            [$this, 'onBeforeApp']
        );
    }
    /**
     * on register check registration form for greenlight flag, if present, register user
     * disable activation for user and add user to greenlight group.
     *
     * @return void
     */
    public function onCustomRegister(UserAssignGroupEvent $event)
    // public function onCustomRegister(ElementEvent $event)
    {
        $this->log("start", __METHOD__);
        $greenlight = Craft::$app->getRequest()->post('whitelabel');
        $this->log("whitelabel: {$greenlight}", __METHOD__);
        if (self::WHITELABEL === $greenlight) {
            $this->log("Registration detected", __METHOD__);
            // manual user activation
            $r = Craft::$app->getUsers()->activateUser($event->user);
            $this->log("Activate user result: {$r}", __METHOD__);
            // assign to group
            $groupId = $this->getGroupId(self::WHITELABEL);
            $this->log(self::WHITELABEL." group id: {$groupId}", __METHOD__);
            // NB: this can be used to assign multiple groups. second param is array of group IDs
            $gr = Craft::$app->getUsers()->assignUserToGroups($event->user->id, [$groupId]);
            $this->log("Assign group to user result: {$gr}", __METHOD__);
        }

        $this->log("end", __METHOD__);
    }

    /**
     * upon login, check user for greenlight group and redirect if required
     * 
     * @return void
     */
    public function onCustomAuth()
    {
        $this->checkGreenLightUser();
    }
    /**
     * handle before app load events as a catch all for greenlight users who come back to the site
     * after login
     *
     * @return void
     */
    public function onBeforeApp()
    {
        $this->checkGreenLightUser();
    }
    /**
     * If the user is in the greenlight user group, redirect to specified url
     *
     * @return void
     */
    private function checkGreenLightUser()
    {
        // first check to ensure we are not coming from SSO 
        if (true == strpos(Craft::$app->getRequest()->getPathInfo(), 'sso')) {
            // SSO request ignore
            return;
        }
        $user = Craft::$app->user;
        // Craft::dd($user);
        if ($user && !$user->getIsGuest()) {
            $groups = Craft::$app->getUserGroups()->getGroupsByUserId($user->id);
            foreach ($groups as $group) {
                if ('GREENLIGHT' === $group->name) {
                    $redirectUrl = env('GREENLIGHT_REDIRECT_URL');
                    if ('' === $redirectUrl) {
                        throw new \Exception("No redirect URL defined");
                    }
                    // redirect to docebo
                    Craft::$app->getResponse()->redirect($redirectUrl);
                    Craft::$app->end();
                }
            }
        }
    }
    /**
     * place holder for future email validation, either via csv or api call.
     *
     * @param string $email
     * 
     * @return bool
     */
    private function validateEmail(string $email): bool
    {
        return true;
    }
    /**
     * takes a group name and searches the craft groups for its ID.
     *
     * @param string $groupName
     * 
     * @return integer
     */
    private function getGroupId(string $groupName): int
    {
        $groups = Craft::$app->userGroups->getAllGroups();
        foreach ($groups as $group) {
            Craft::dump($group->name.':'.$group->id);
            Craft::dump(strtolower($group->name) === strtolower($groupName));
            if (strtolower($group->name) === strtolower($groupName)) {
                return $group->id;
            }
        }

        throw new \Exception("Group Not found");
    }
    /**
     * logs stuff
     *
     * @param string $log
     * @param string $method
     * 
     * @return void
     */
    private function log(string $log, string $method): void
    {
        Craft::info(
            "GLP: {$log}",
            $method
        );
    }
}
