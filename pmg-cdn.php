<?php
/*
Plugin Name: PMG CDN
Plugin URI: http://pmg.co
Description: A simple plugin that enables an origin pull CDN on your WordPress site.
Version: 1.0
Text Domain: pmgcdn
Author: Christopher Davis
Author URI: http://pmg.co/people/chris
License: GPL2

    Copyright 2012 Performance Media Group <seo@pmg.co>

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

!defined('ABSPATH') && exit;

PMG_CDN::init();

class PMG_CDN
{
    // option name
    const SETTING = 'pmg_cdn_settings';

    // page on which the options reside
    const PAGE = 'media';

    // container for the instance
    private static $ins = null;

    // extension urls to change
    private $ext = array();

    // Only user uploads or everything?
    private $uploads = false;

    // site url
    private $site_url;

    // CDN url
    private $cdn_url;

    public static function instance()
    {
        is_null(self::$ins) && self::$ins = new self;
        return self::$ins;
    }

    public static function init()
    {
        add_action('plugins_loaded', array(self::instance(), '_setup'));
    }

    public static function opt($key, $default='')
    {
        $opts = get_option(self::SETTING, array());
        return isset($opts[$key]) ? $opts[$key] : $default;
    }

    private static function opt_name($k)
    {
        return sprintf('%s[%s]', self::SETTING, $k);
    }

    public function _setup()
    {
        $this->ext = apply_filters(
            'pmg_cdn_extensions',
            array('jpe?g', 'gif', 'png', 'css', 'bmp', 'js', 'ico',)
        );

        $this->uploads = apply_filters(
            'pmg_cdn_uploads_only',
            defined('CDN_UPLOADS_ONLY') ?
                CDN_UPLOADS_ONLY : static::opt('uploads', 'on') == 'on'
        );

        $this->site_url = parse_url(home_url(), PHP_URL_HOST);
        $this->cdn_url = defined('CDN_HOST') ? CDN_HOST : self::opt('cdn_host', false);

        add_action('admin_init', array($this, 'settings'));

        // make sure we can do this
        if(
            (defined('CDN_DISABLE') && CDN_DISABLE) ||
            !$this->cdn_url ||
            !$this->ext
        ) return;

        add_action('template_redirect', array($this, 'start_ob'));

        if($this->uploads)
            add_filter('cdn_filter', array($this, 'filter_uploads'));
        else
            add_filter('cdn_filter', array($this, 'filter_all'));
    }

    /********** Hooks **********/

    /**
     * Hooked into `admin_init` registers settinsgs for this plugins.
     *
     * @since   1.0
     * @access  public
     * @uses    register_setting
     * @uses    add_settings_section
     * @uses    add_settings_field
     * @return  void
     */
    public function settings()
    {
        register_setting(
            self::PAGE,
            self::SETTING,
            array($this, 'validate')
        );

        add_settings_section(
            'cdn-settings',
            __('CDN Settings', 'pmgcdn'),
            array($this, 'help_cb'),
            self::PAGE
        );

        add_settings_field(
            'cdn-host',
            __('CDN Host', 'pmgcdn'),
            array($this, 'host_cb'),
            self::PAGE,
            'cdn-settings',
            array('label_for' => self::opt_name('cdn_host'), 'key' => 'cdn_host')
        );

        add_settings_field(
            'cdn-uploads',
            __('Uploads Only?', 'pmgcdn'),
            array($this, 'uploads_cb'),
            self::PAGE,
            'cdn-settings',
            array('label_for' => self::opt_name('uploads'), 'key' => 'uploads')
        );
    }

    /**
     * Hooked into `tempalte_redirect`.  Starts an output buffer for this plugin.
     *
     * @since   1.0
     * @access  public
     * @return  void
     */
    public function start_ob()
    {
        ob_start(array($this, 'ob'));
    }

    /**
     * Hooked into `cdn_filter`.  Switches out all URLs that end in one of
     * $this->ext with their CDN equivilents.
     *
     * @since   1.0
     * @access  public
     * @param   string $output
     * @return  string
     */
    public function filter_all($output)
    {
        return preg_replace(
            "#=(['\"])" // open equal sign and opening quote
            . "((?:https?:)?//{$this->site_url})?/" // domain (optional)
            . "((?:(?!\\1).)+)" // look for anything that's not our opening quote
            . "\.(" . implode('|', $this->ext) . ")" // extensions
            . "(\?((?:(?!\\1).))+)?" // match query strings
            . "\\1#", // closing quote
            '=$1//' . $this->cdn_url . '/$3.$4$5$1',
            $output
        );
    }

    /**
     * Hooked into `cdn_filter`.  Switches user uploads with their CDN
     * domain equivilent.
     *
     * @since   1.0
     * @access  public
     * @param   string $output;
     * @return  string
     */
    public function filter_uploads($output)
    {
        $uploads = wp_upload_dir();

        // deal with protocol relative urls
        if(preg_match('#^//#', $uploads['baseurl']))
        {
            $uploads['baseurl'] = 'http://' . ltrim($uploads['baseurl'], '/');
        }

        $domain = preg_quote(parse_url($uploads['baseurl'], PHP_URL_HOST), '#');
        $path = preg_quote(parse_url($uploads['baseurl'], PHP_URL_PATH), '#');

        return preg_replace(
            "#=(['\"])" // open equal sign and opening quote
            . "((?:https?:)?//{$domain})?{$path}/" // domain (optional) and upload path
            . "((?:(?!\\1).)+)" // look for anything that's not our opening quote
            . "\.(" . implode('|', $this->ext) . ")" // extensions
            . "(\?((?:(?!\\1).))+)?" // match query strings
            . "\\1#u", // closing quote
            '=$1//' . $this->cdn_url . $path . '/$3.$4$5$1',
            $output
        );
    }

    /**
     * Output buffer callback.  Nothing existing, just an apply filters call.
     *
     * @since   1.0
     * @access  public
     * @uses    apply_filters
     * @param   string $output The output buffer string
     * @return  string
     */
    public function ob($output)
    {
        return apply_filters('cdn_filter', $output);
    }

    /********** Settings Callbacks **********/

    public function validate($dirty)
    {
        $c = array();

        $c['cdn_host'] = isset($dirty['cdn_host']) ? $dirty['cdn_host'] : '';
        $c['uploads'] = !empty($dirty['uploads']) ? 'on' : 'off';

        return $c;
    }

    public function help_cb()
    {
        echo '<p class="description">';
        esc_html_e('Settings for your origin pull CDN. CDN Host should be ' .
            'the CDN hostname -- no http. Checking "uploads only" will force ' .
            'this plugin to only swap out user uploads with CDN urls.',
            'pmgcdn'
        );
        echo '</p>';
    }

    public function host_cb($args)
    {
        printf(
            '<input type="text" class="regular-text" name="%1$s" id="%1$s" value="%2$s" />',
            esc_attr($args['label_for']),
            esc_attr(self::opt($args['key']))
        );
    }

    public function uploads_cb($args)
    {
        printf(
            '<input type="checkbox" name="%1$s" id="%1$s" value="on" %2$s />',
            esc_attr($args['label_for']),
            checked('on', self::opt($args['key'], 'off'), false)
        );
    }
}
