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
use craft\web\ErrorHandler;
use craft\events\RegisterEmailMessagesEvent;
use craft\web\User as WebUser;
use craft\services\Sites;
use craft\services\SystemMessages;

use yii\base\ModelEvent;
use GuzzleHttp\Client;

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
     public function registerEmailMessages()
       {
           return array(
               'welcome_whitelabel'
           );
       }

    public function init()
    {
        parent::init();
        self::$plugin = $this;

        $this->_registerEmailMessages();

        // Register Events
        Event::on(
            User::class,
            User::EVENT_BEFORE_VALIDATE,
            [$this, 'beforeRegistrationValidate']
        );
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
     * validates a user email address on registration for GL whitelabels only
     *
     * @param ModelEvent $e
     * @return void
     */
    public function beforeRegistrationValidate(ModelEvent $e)
    {
        $this->log("start", __METHOD__);
        $user = $e->sender;
        $greenlight = Craft::$app->getRequest()->post('whitelabel');
        $this->log("whitelabel: {$greenlight}", __METHOD__);
        if (self::WHITELABEL === $greenlight && !$this->validateEmail($user->email)) {
            $user->addError('email', "Email not authorised. Please contact cpd.requests@greenlightsupplements.com for assistance.");
        }

        $this->log("end", __METHOD__);
    }


    /**
     * on register check registration form for greenlight flag, if present, register user
     * disable activation for user and add user to greenlight group.
     *
     * @return void
     */
    public function onCustomRegister(UserAssignGroupEvent $event)
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
            $group = Craft::$app->userGroups->getGroupByHandle(self::WHITELABEL);
            // NB: this can be used to assign multiple groups. second param is array of group IDs
            //$gr = "TEST";
            $gr = Craft::$app->getUsers()->assignUserToGroups($event->user->id, [$group->id]);
            $this->sendActivationEmail($event->user);
            $this->log("Assign group to user result: {$gr}", __METHOD__);
        }

        $this->log("end", __METHOD__);
    }

    private function _registerEmailMessages()
    {
        Event::on(SystemMessages::class, SystemMessages::EVENT_REGISTER_MESSAGES, function(RegisterEmailMessagesEvent $event) {
            $event->messages = array_merge($event->messages, [
                [
                    'key' => 'welcome_whitelabel',
                    'heading' => Craft::t('green-light-auth', 'welcome_whitelabel_heading'),
                    'subject' => Craft::t('green-light-auth', 'welcome_whitelabel_subject'),
                    'body' => Craft::t('green-light-auth', 'welcome_whitelabel_body'),
                ]
            ]);
        });
    }

    public function sendActivationEmail(craft\elements\user $user)
    {
        $this->log("start", __METHOD__);
        $base = Craft::$app->getSites()->getPrimarySite()->getBaseUrl();
        if(substr($base, -1) !== "/"){
          $base = $base . "/";
        }

          try {

          $message = Craft::$app->getMailer()->composeFromKey('welcome_whitelabel', ['subject' => 'Welcome to Greenlight'])->setTo($user)->setFrom(['no-reply@greenlight.com' => 'Green Light'])->setHtmlBody(
            Craft::$app->getView()->renderTemplate('mail/welcome-white',[
              'user'  => $user,
              'logo' =>  $base . "assets/greenlight-logo.png",
              'body' => "Welcome to the Green Light<br/><br/>Best Regards<br/><br/>Green Light"

            ])
          );
          $message->send();

        } catch (\Throwable $e) {

          $err = new ErrorHandler();
          $err->handleException($e);

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
        if (strpos(Craft::$app->getRequest()->getPathInfo(), 'sso') !== false) {
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
        $client = new Client([
            // Base URI is used with relative requests
            'base_uri' => 'https://support.greenlightsupplements.com/wp-json/wp/v2/is-email-valid-for-cpd-course/',
            // You can set any number of default request options.
            'timeout'  => 2.0,
        ]);
        $response = $client->request('GET', $email);
        $body = $response->getBody();
        $r = json_decode($body->getContents());

        return $r->validEmailForCourse;
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
            'autumndev'
        );
    }
}
