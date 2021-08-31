<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'ext_captcha';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '0.1.0';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'https://stefdawson.com/';
$plugin['description'] = 'Implementation of hCaptcha / ReCaptcha spam prevention for Textpattern CMS';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '3';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '5';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '1';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

$plugin['textpack'] = <<<EOT
#@language en, en-gb, en-us
#@ext_captcha
ext_captcha => Captcha
ext_captcha_score_threshold => Score threshold (0.0 - 1.0)
ext_captcha_secret_key => Secret key
ext_captcha_set_key => Set the site/secret keys in Prefs before using this tag
ext_captcha_site_key => Site key
ext_captcha_timeout => [ReCaptcha Only] Timeout (seconds)
ext_captcha_failed => Cannot fetch captcha response. Check your server settings.
EOT;

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
/**
 * ext_captcha
 *
 * A Textpattern CMS plugin for adding hCaptcha / ReCaptcha to forms and pages
 *
 * @author Stef Dawson
 * @link   https://stefdawson.com/
 */

if (txpinterface === 'admin') {
    new ext_captcha();
} elseif (txpinterface === 'public') {
    // Need to register comment spam checks independently of the captcha tag.
    register_callback('ext_captcha_submit', 'comment.save');

    if (class_exists('\Textpattern\Tag\Registry')) {
        Txp::get('\Textpattern\Tag\Registry')
            ->register('ext_captcha');
    }
}

/**
 * Public tag: add captcha to the page.
 *
 * @param  array  $atts  Tag attributes
 * @param  string $thing Tag container content
 * @return string        HTML
 */
function ext_captcha($atts, $thing = null)
{
    // @todo: Opt in these somehow?
    register_callback('ext_captcha_submit', 'comconnect.submit');
    register_callback('ext_captcha_submit', 'zemcontact.submit');
    register_callback('ext_captcha_submit', 'mem_form.submit');

    // @todo: Use the action to somehow alter the outcome.
    extract(lAtts(array(
        'action'   => 'homepage',
        'class'    => __FUNCTION__,
        'nonce'    => null,
        'response' => '',
        'type'     => 'hcaptcha', // hcaptcha/recaptcha
    ), $atts));

    $out = array();
    $siteKey = get_pref('ext_captcha_site_key');
    $secretKey = get_pref('ext_captcha_secret_key');

    if (empty($siteKey) || empty($secretKey)) {
        trigger_error(gTxt('ext_captcha_set_key'));

        return;
    }

    if ($type === 'hcaptcha') {
        $apiUrl = 'https://hcaptcha.com/1/api.js';
        $out[] = '<div class="h-captcha" data-sitekey="'.txpspecialchars($siteKey).'"></div>';

        if (class_exists('\Textpattern\UI\Script')) {
            $out[] = Txp::get('\Textpattern\UI\Script')->setSource($apiUrl)->setBool('async, defer');
        } else {
            $out[] = script_js($apiUrl, TEXTPATTERN_SCRIPT_URL);
        }
    } elseif ($type === 'recaptcha') {
        $out[] = '<input type="hidden" name="recaptchaResponse" id="recaptchaResponse" />';
        $apiJs = <<<EOJS
window.onload = function() {
    var scriptag = document.createElement('script');
    scriptag.setAttribute('src', 'https://www.google.com/recaptcha/api.js?render={$siteKey}');

    if ('{$nonce}' !== '') {
        scriptag.setAttribute('nonce', '{$nonce}');
    }

    scriptag.onload = function()  {
        grecaptcha.ready(function () {
            grecaptcha.execute('{$siteKey}', { action: '{$action}' }).then(function (token) {
                var recaptchaResponse = document.getElementById('recaptchaResponse');
                recaptchaResponse.value = token;
            });
        });
    }

    document.head.appendChild(scriptag);
};
EOJS;

        if (class_exists('\Textpattern\UI\Script')) {
            $out[] = Txp::get('\Textpattern\UI\Script')->setContent($apiJs);
        } else {
            $out[] = script_js($apiJs);
        }
    }

    return implode(n, $out);
}

/**
 * Handle Captcha submission and validation
 */
function ext_captcha_submit()
{
    global $plugins, $event;

    $result = false;
    $errors = '';

    $siteKey = filter_var(get_pref('ext_captcha_site_key'), FILTER_SANITIZE_STRING);
    $secretKey = filter_var(get_pref('ext_captcha_secret_key'), FILTER_SANITIZE_STRING);
    $scoreThreshold = filter_var(get_pref('ext_captcha_score_threshold'), FILTER_SANITIZE_NUMBER_FLOAT);
    $timeout = filter_var(get_pref('ext_captcha_timeout'), FILTER_SANITIZE_NUMBER_INT);
    $remoteIp = filter_var(serverSet('REMOTE_ADDR'), FILTER_VALIDATE_IP);
    $recaptchaResponse = ps('recaptchaResponse');
    $hcaptchaResponse = ps('h-captcha-response');

    if ($hcaptchaResponse) {
        $endpoint = 'https://hcaptcha.com/siteverify';
        $response = filter_var($hcaptchaResponse, FILTER_SANITIZE_STRING);

        $data = array(
            'secret'   => $secretKey,
            'sitekey'  => $siteKey,
            'response' => $response,
            'remoteip' => $remoteIp,
        );

        if (function_exists('curl_version')) {
            $verify = curl_init();

            curl_setopt($verify, CURLOPT_URL, $endpoint);
            curl_setopt($verify, CURLOPT_POST, true);
            curl_setopt($verify, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($verify, CURLOPT_RETURNTRANSFER, true);

            $ret = curl_exec($verify);
        } elseif (ini_get('allow_url_fopen')) {
            // Fallback on GET.
            $ret = file_get_contents($endpoint.'?secret='.$secretKey.'&response='.$hcaptchaResponse.'&sitekey='.$siteKey.'&remoteip='.$remoteIp);
        } else {
            $ret = json_encode(array('success' => false, 'error-codes' => gTxt('ext_captcha_failed')));
        }

        $retData = json_decode($ret, true);

        if ($retData['success']) {
            $result = true;
        } else {
            // return error to user; they did not pass
            $errors = $retData['error-codes'];
        }
    } elseif ($recaptchaResponse) {
        $response = filter_var($recaptchaResponse, FILTER_SANITIZE_STRING);

        // Load the ReCaptcha class from the plugin's own dir.
        if (!class_exists('\ReCaptcha\ReCaptcha')) {
            $loader = new \Textpattern\Loader(__DIR__);
            $loader->register();
        }

        if (class_exists('\ReCaptcha\ReCaptcha')) {
            $recaptcha = Txp::get('\ReCaptcha\ReCaptcha', $secretKey);
            $recaptcha->setExpectedHostname(get_pref('siteurl'));

            if ($scoreThreshold >= 0 && $scoreThreshold <= 1.0) {
                $recaptcha->setScoreThreshold($scoreThreshold);
            }

            if (is_int($timeout) && $timeout > 0) {
                $recaptcha->setChallengeTimeout($timeout);
            }

            // Todo: set language to front-side lang?

            // Verify the ReCaptcha response.
            $ret = $recaptcha->verify($response, $remoteIp);

            if ($ret->isSuccess()) {
                $result = true;
            } else {
                $errors = $ret->getErrorCodes();
            }
        } else {
            // Plugin not installed properly.
        }
    }

    // Signal back to the calling environment that captcha failed.
    if ($result === false) {
        // @todo:
        // * Raise a callback so custom actions can be applied?
        // * Use the action to determine what to do?
        if ($event === 'comment.save') {
            $evaluation =& get_comment_evaluator();
            $evaluation->add_estimate(SPAM);
        } elseif (in_array('com_connect', $plugins)) {
            $evaluation =& get_comconnect_evaluator();
            $evaluation->add_comconnect_status(1);

            if (method_exists($evaluation, 'add_comconnect_reason')) {
                $evaluation->add_comconnect_reason($errors);
            }
        } elseif (in_array('zem_contact', $plugins)) {
            $evaluation =& get_zemcontact_evaluator();
            $evaluation->add_zemcontact_status(1);
        } elseif (in_array('mem_form', $plugins)) {
            $evaluation =& get_mem_form_evaluator();
            $evaluation->add_status(1);
        }
    }
}

/**
 * Admin-side user interface.
 */
class ext_captcha
{
    /**
     * Plugin event as registered in Txp.
     *
     * @var string
     */
    protected $event = 'ext_captcha';

    /**
     * Plugin privileges required for the prefs.
     *
     * @var string
     */
    protected $privs = '1,2';

    /**
     * Constructor to set up callbacks and environment.
     */
    public function __construct()
    {
        add_privs($this->event, $this->privs);
        add_privs('prefs.'.$this->event, $this->privs);
        add_privs('plugin_prefs.'.$this->event, $this->privs);
        register_callback(array($this, 'prefs'), 'prefs', '', 1);
        register_callback(array($this, 'options'), 'plugin_prefs.'.$this->event, null, 1);
    }

    /**
     * Jump to the prefs panel from the Options link on the Plugins panel.
     */
    public function options()
    {
        $link = '?event=prefs#prefs_group_'.$this->event;

        header('Location: ' . $link);
    }

    /**
     * Install prefs if they don't already exist.
     *
     * @param  string $evt Textpattern event (panel)
     * @param  string $stp Textpattern step (action)
     */
    public function prefs($evt, $stp)
    {
        $ext_captcha_prefs = $this->getPrefs();

        foreach ($ext_captcha_prefs as $key => $prefobj) {
            if (get_pref($key) === '') {
                set_pref($key, doSlash($prefobj['default']), $this->event, $prefobj['type'], $prefobj['html'], $prefobj['position'], $prefobj['visibility']);
            }
        }
    }

    /**
     * Settings for the plugin.
     *
     * @return array  Preference set
     */
    function getPrefs()
    {
        $ext_captcha_prefs = array(
            'ext_captcha_site_key' => array(
                'html'       => 'text_input',
                'type'       => PREF_PLUGIN,
                'position'   => 10,
                'default'    => '',
                'group'      => 'ext_captcha_admin',
                'visibility' => PREF_GLOBAL,
            ),
            'ext_captcha_secret_key' => array(
                'html'       => 'text_input',
                'type'       => PREF_PLUGIN,
                'position'   => 20,
                'default'    => '',
                'group'      => 'ext_captcha_admin',
                'visibility' => PREF_GLOBAL,
            ),
            'ext_captcha_score_threshold' => array(
                'html'       => 'text_input',
                'type'       => PREF_PLUGIN,
                'position'   => 30,
                'default'    => '0.5',
                'group'      => 'ext_captcha_settings',
                'visibility' => PREF_GLOBAL,
            ),
            'ext_captcha_timeout' => array(
                'html'       => 'text_input',
                'type'       => PREF_PLUGIN,
                'position'   => 40,
                'default'    => '120',
                'group'      => 'ext_captcha_settings',
                'visibility' => PREF_GLOBAL,
            ),
        );

        return $ext_captcha_prefs;
    }
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. ext_captcha

An implementation of either ReCaptcha (v3) or hCaptcha for detecting bot/spammer interaction with your site.

h2. Installation / Uninstallation

After "downloading the plugin":https://github.com/Bloke/ext_captcha follow the instructions below depending on your version of Textpattern. For bug reports, please "raise an issue":https://github.com/Bloke/ext_captcha/issues.

h3. For Textpattern 4.8.x+

Upload the plugin's .zip file to the _Admin->Plugins_ panel, install and enable the plugin.
If you only intend to use hCaptcha, you can delete the textpattern/vendors/ReCaptcha directory or simply install the plugin .txt file bundled inside the .zip.

h3. For Textpattern 4.7.x

# Unpack the plugin's .zip file.
# If you're intending to use ReCaptcha, upload the ReCaptcha library to your textpattern/vendors directory.
# Paste the code from the .txt file into the Textpattern _Admin->Plugins_ panel, install and enable the plugin.

h3. To uninstall

* Delete the plugin from the _Admin->Plugins_ panel.
* Remove ReCaptcha from the textpattern/vendors directory if it's there and you no longer need it.

h2. Setup for hCaptcha

# Visit the "hcaptcha registration site":https://dashboard.hcaptcha.com/signup
# Follow the instructions for obtaining site/secret keys for hCaptcha on your chosen domain.
# Visit your site's _Admin->Prefs_ panel and click the Captcha group from the list.
# Copy and paste the Site and Secret keys into the relevant boxes.
# Adjust the Score threshold if you are using an Enterprise account. 0.5 is average. hCaptcha uses risk scores that are the inverse of ReCaptcha. Higher values (towards 1.0) indicate a greater threat. 0.0 is no risk.
# Save the prefs.

h2. Setup for ReCaptcha

# Visit the "ReCaptcha registration site":https://g.co/recaptcha/v3.
# Follow the instructions for obtaining site/secret keys for _ReCaptcha v3_ on your chosen domain.
# Visit your site's _Admin->Prefs_ panel and click the Captcha group from the list.
# Copy and paste the Site and Secret keys into the relevant boxes.
# Adjust the Score threshold if you wish. 0.5 is average. Higher values (towards 1.0) are more stringent, i.e. content with a higher score is required to pass. Lower values, towards zero, are more lenient.
# Adjust the time that the captcha response remains valid (default is 120 seconds). If you have complex forms you may wish to raise this. Set it to zero to disable this feature.
# Save the prefs.

h2. Usage after setup

Somewhere in your Page/Form template that you wish to employ the captcha, add this tag:

bc. <txp:ext_captcha /> or <ext::captcha />

If the plugin is used within a com_connect form, a zem_contact form, a mem_form or a Textpattern comment form and the test fails, the plugin will raise a "SPAM" evaluation result, forbidding the form submission.

h2. Additional security / CSP

Todo.

h2. Author / credits

Written by "Stef Dawson":https://stefdawson.com/contact. Rowan Merewood for the "recaptcha v3 library":https://github.com/google/recaptcha.

# --- END PLUGIN HELP ---
-->
<?php
}
?>