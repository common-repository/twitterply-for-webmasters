<?php

/*
    Plugin Name: Twitterply
    Plugin URI: http://www.iwebslog.com/
    Version: 1.2
    Author: Iwebslog Labs
    Author URI: http://www.iwebslog.com/
    Description: Display your twitter feeds or Tweets on your website or blog post/page or in the sidebar. This plugin uses PHP to make requests to the Twitter REST API.
    License: GNU General Public License v2.0 or later
    License URI: http://www.opensource.org/licenses/gpl-license.php
*/


if ( class_exists( 'Twitterply' ) )
    Twitterply::get_instance();


class Twitterply {
    private static $instance;
    public static $version = '1.0.3';
    public static $refresh = 300;
    public static $registration_url = 'http://dev.twitter.com/apps/new';
    public static function get_instance() {
    
        if ( !self::$instance instanceof self )
            self::$instance = new self;
        return self::$instance;
    
    }
    
    private function __construct() {

        /** Get the plugin name */
        $plugin = plugin_basename( __FILE__ );

        /** Load plugin textdomain for language capabilities */
        load_plugin_textdomain( 'twitterply', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        /** Activation and deactivation hooks. Static methods are used to avoid activation/uninstallation scoping errors. */
        if ( is_multisite() ) {
            register_activation_hook( __FILE__, array( __CLASS__, 'do_network_activation' ) );
            register_uninstall_hook( __FILE__, array( __CLASS__, 'do_network_uninstall' ) );
        }
        else {
            register_activation_hook( __FILE__, array( __CLASS__, 'do_activation' ) );
            register_uninstall_hook( __FILE__, array( __CLASS__, 'do_uninstall' ) );
        }

        /** Hooks actions & shortcodes */
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_menu', array( $this, 'save_settings' ) );
        add_filter( "plugin_action_links_{$plugin}", array( $this, 'add_settings_link' ) );
        add_shortcode( 'twitterply', array( $this, 'do_shortcode' ) );

        /** Custom actions hook */
        do_action_ref_array( 'twitterply', array( $this ) );

    }
    
    /**
     * Executes a network activation
     *
     * @since 2.0
     */
    public static function do_network_activation() {
        self::get_instance()->network_activate();
    }
    
    /**
     * Executes a network uninstall
     *
     * @since 2.0
     */
    public static function do_network_uninstall() {
        self::get_instance()->network_uninstall();
    }
    
    /**
     * Executes an activation
     *
     * @since 2.0
     */
    public static function do_activation() {
        self::get_instance()->activate();
    }
    
    /**
     * Executes an uninstall
     *
     * @since 2.0
     */
    public static function do_uninstall() {
        self::get_instance()->uninstall();
    }
    
    /**
     * Network activation hook
     *
     * @since 2.0
     */
    public function network_activate() {

        /** Do plugin version check */
        if ( !$this->version_check() )
            return;

        /** Get all of the blogs */
        $blogs = $this->get_multisite_blogs();

        /** Execute acivation for each blog */
        foreach ( $blogs as $blog_id ) {
            switch_to_blog( $blog_id );
            $this->activate();
            restore_current_blog();
        }

        /** Trigger hooks */
        do_action_ref_array( 'twitterply_network_activate', array( $this ) );

    }
    
    /**
     * Network uninstall hook
     *
     * @since 2.0
     */
    public function network_uninstall() {

        /** Get all of the blogs */
        $blogs = $this->get_multisite_blogs();

        /** Execute uninstall for each blog */
        foreach ( $blogs as $blog_id ) {
            switch_to_blog( $blog_id );
            $this->uninstall();
            restore_current_blog();
        }

        /** Trigger hooks */
        do_action_ref_array( 'twitterply_network_uninstall', array( $this ) );

    }
    
    /**
     * Activation hook
     *
     * @since 2.0
     */
    public function activate() {

        /** Do plugin version check */
        if ( !$this->version_check() )
            return;

        /** Add database options */
        add_option( 'twitterply_version', self::$version );
        add_option( 'twitterply_settings', array(
            'consumer_key' => null,
            'consumer_secret' => null,
            'access_token' => null,
            'access_token_secret' => null,
            'screen_name' => 'iwebslogtech',
            'count' => 5,
            'include_rts' => true,
            'exclude_replies' => false
        ) );

        /** Trigger hooks */
        do_action_ref_array( 'twitterply_activate', array( $this ) );

    }
    
    /**
     * Uninstall Hook
     *
     * @since 1.0
     */
    public function uninstall() {

        /** Delete options and transients */
        delete_option( 'twitterply_version' );
        delete_option( 'twitterply_settings' );
        delete_transient( 'twitterply_tweets' );

        /** Trigger hooks */
        do_action_ref_array( 'twitterply_uninstall', array( $this ) );

    }
    
    /**
     *  Does a plugin version check, making sure the current Wordpress version is supported. If not, the plugin is deactivated and an error message is displayed.
     *
     *  @version 1.0
     */
    public function version_check() {
        global $wp_version;
        if ( version_compare( $wp_version, '3.5', '<' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( __( sprintf( 'Sorry, but your version of WordPress, <strong>%s</strong>, is not supported. The plugin has been deactivated. <a href="%s">Return to the Dashboard.</a>', $wp_version, admin_url() ), 'twitterply' ) );
            return false;
        }
        return true;
    }
    
    /**
     * Returns the ids of the various multisite blogs. Returns false if not a multisite installation.
     *
     * @since 1.0.2
     */
    public function get_multisite_blogs() {

        global $wpdb;

        /** Bail if not multisite */
        if ( !is_multisite() )
            return false;

        /** Get the blogs ids from database */
        $query = "SELECT blog_id from $wpdb->blogs";
        $blogs = $wpdb->get_col($query);

        /** Push blog ids to array */
        $blog_ids = array();
        foreach ( $blogs as $blog )
            $blog_ids[] = $blog;

        /** Return the multisite blog ids */
        return $blog_ids;

    }

    /**
     * Adds a plugin settings page
     *
     * @since 1.0
     */
    public function add_settings_page() {
        add_options_page(
            __( 'Twitter Feed Settings', 'twitterply' ),
            __( 'Twitter Feed', 'twitterply' ),
            'edit_plugins',
            'twitterply',
            array( $this, 'settings_view' )
        );
    }

    /**
     * Adds a settings link to the "Plugins" panel
     *
     * @since 1.0.3
     */
    public function add_settings_link($links) {
        array_unshift($links, '<a href="options-general.php?page=twitterply">Settings</a>');
        return $links; 
    }

    /**
     * Validates the settings
     *
     * @since 1.0
     */
    public function validate_settings( $settings ) {
        foreach ( $settings as $index => $setting ) {
            if ( $setting === 'true' || $setting === 'false' )
                $settings[ $index ] = filter_var( $setting, FILTER_VALIDATE_BOOLEAN );
        }
        return $settings;
    }

    /**
     * Saves the plugin settings
     *
     * @since 1.0
     */
    public function save_settings() {

        /** Bail if not our plugin page or not saving settings */
        if ( !isset( $_GET['page'] ) )
            return;
        if ( $_GET['page'] != 'twitterply' )
            return;
        if ( !isset( $_POST['settings'] ) )
            return;

        /** Security check. */
        if ( !check_admin_referer( "twitterply-save_{$_GET['page']}", "twitterply-save_{$_GET['page']}" ) ) {
            wp_die( __( 'Security check has failed. Save has been prevented. Please try again.', 'twitterply' ) );
            exit();
        }

        /** Save the settings */
        update_option( 'twitterply_settings', stripslashes_deep( $this->validate_settings( $_POST['settings'] ) ) );

        /** Delete the old transient to force a refresh */
        delete_transient( 'twitterply_tweets' );

        /** Display success message */
        add_action( 'admin_notices', create_function( '', 'echo "<div class=\"message updated\"><p>'. __( 'Settings have been saved successfully.', 'twitterply' ) .'</p></div>";' ) );

    }
    
    /**
     * Executes a shortcode handler
     *
     * @since 1.0
     */
    public function do_shortcode( $atts ) {

        /** Return the tweets to be printed by the shortcode */
        ob_start();
        $this->show();
        return ob_get_clean();

    }

    /**
     * Prints the settings page view
     *
     * @since 1.0
     */
    public function settings_view() {

    /** Get the plugin settings */
    $settings = $s = $this->validate_settings( get_option( 'twitterply_settings' ) );

    /** Print the view */
    ?>
    <div class="wrap">
        <div id="icon-edit" class="icon32 icon32-posts-post"><br></div>
        <h2><?php _e( 'Twitter Feed Configuration', 'twitterply' ); ?></h2>
        <form name="post" action="options-general.php?page=twitterply" method="post">
            <?php
                /** Security nonce field */
                wp_nonce_field( "twitterply-save_{$_GET['page']}", "twitterply-save_{$_GET['page']}", false );
            ?>

            <div class="main-panel">
                <div class="section">
                    <h3><?php _e( 'Authentication', 'twitterply' ); ?></h3>
                    <p><?php _e( 'Twitter\'s v1.1 API requires authentication. For this you need to <a href="'. self::$registration_url .'">register an application here</a>. Follow the instructions and that\'s it, you\'re authenticated.', 'twitterply' ); ?></p>
                    <table class="form-table settings">
                        <tbody>
                            <tr valign="top">
                                <th scope="row"><label for="consumer_key"><?php _e( 'Consumer Key', 'twitterply' ); ?></label></th>
                                <td>
                                    <input type="text" name="settings[consumer_key]" id="consumer_key" class="regular-text" value="<?php echo $s['consumer_key']; ?>">
                                    <p class="description"><?php _e( 'Enter your Consumer Key.', 'twitterply' ); ?></p>
                                </td>
                            </tr>

                            <tr valign="top">
                                <th scope="row"><label for="consumer_secret"><?php _e( 'Consumer Secret', 'twitterply' ); ?></label></th>
                                <td>
                                    <input type="password" name="settings[consumer_secret]" id="consumer_secret" class="regular-text" value="<?php echo $s['consumer_secret']; ?>">
                                    <p class="description"><?php _e( 'Enter your Consumer Secret. Keep this private, do not share it.', 'twitterply' ); ?></p>
                                </td>
                            </tr>

                            <tr valign="top">
                                <th scope="row"><label for="access_token"><?php _e( 'Access Token', 'twitterply' ); ?></label></th>
                                <td>
                                    <input type="text" name="settings[access_token]" id="access_token" class="regular-text" value="<?php echo $s['access_token']; ?>">
                                    <p class="description"><?php _e( 'Enter your Access Token.', 'twitterply' ); ?></p>
                                </td>
                            </tr>

                            <tr valign="top">
                                <th scope="row"><label for="access_token_secret"><?php _e( 'Access Token Secret', 'twitterply' ); ?></label></th>
                                <td>
                                    <input type="password" name="settings[access_token_secret]" id="access_token_secret" class="regular-text" value="<?php echo $s['access_token_secret']; ?>">
                                    <p class="description"><?php _e( 'Enter your Access Token Secret. Keep this private also, do not share it.', 'twitterply' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="section">
                    <h3><?php _e( 'Configuration', 'twitterply' ); ?></h3>
                    <p><?php _e( 'Here you can alter some of the basic Twitter feed settings.', 'twitterply' ); ?></p>
                    <table class="form-table settings">
                        <tbody>
                            <tr valign="top">
                                <th scope="row"><label for="screen_name"><?php _e( 'Screen Name', 'twitterply' ); ?></label></th>
                                <td>
                                    <input type="text" name="settings[screen_name]" id="screen_name" class="regular-text" value="<?php echo $s['screen_name']; ?>">
                                    <p class="description"><?php _e( 'The screen name of the user for whom to return results for.', 'twitterply' ); ?></p>
                                </td>
                            </tr>

                            <tr valign="top">
                                <th scope="row"><label for="count"><?php _e( 'Count', 'twitterply' ); ?></label></th>
                                <td>
                                    <input type="number" step="1" min="1" name="settings[count]" id="count" value="<?php echo $s['count']; ?>">
                                    <p class="description"><?php _e( 'Specifies the number of tweets to try and retrieve, up to a maximum of 200.', 'twitterply' ); ?></p>
                                </td>
                            </tr>

                            <tr valign="top">
                                <th scope="row"><?php _e( 'Include Retweets', 'twitterply' ); ?></th>
                                <td>
                                    <fieldset>
                                        <legend class="screen-reader-text"><span><?php _e( 'Include Retweets', 'twitterply' ); ?></span></legend>
                                        
                                        <label for="include_rts_true"><input type="radio" name="settings[include_rts]" id="include_rts_true" value="true" <?php checked( $s['include_rts'], true ); ?>>
                                            <span><?php _e( 'Yes', 'twitterply' ); ?></span>
                                        </label>
                                        <br />

                                        <label for="include_rts_false"><input type="radio" name="settings[include_rts]" id="include_rts_false" value="false" <?php checked( $s['include_rts'], false ); ?>>
                                            <span><?php _e( 'No', 'twitterply' ); ?></span>
                                        </label>
                                    </fieldset>
                                    <p class="description"><?php _e( 'When set to "No", the timeline will not show any retweets.', 'twitterply' ); ?></p>
                                </td>
                            </tr>

                            <tr valign="top">
                                <th scope="row"><?php _e( 'Exclude Replies', 'twitterply' ); ?></th>
                                <td>
                                    <fieldset>
                                        <legend class="screen-reader-text"><span><?php _e( 'Exclude Replies', 'twitterply' ); ?></span></legend>
                                        
                                        <label for="exclude_replies_true"><input type="radio" name="settings[exclude_replies]" id="exclude_replies_true" value="true" <?php checked( $s['exclude_replies'], true ); ?>>
                                            <span><?php _e( 'Yes', 'twitterply' ); ?><span>
                                        </label>
                                        <br />
                                        
                                        <label for="exclude_replies_false"><input type="radio" name="settings[exclude_replies]" id="exclude_replies_false" value="false" <?php checked( $s['exclude_replies'], false ); ?>>
                                            <span><?php _e( 'No', 'twitterply' ); ?><span>
                                        </label>
                                    </fieldset>
                                    <p class="description"><?php _e( 'This parameter will prevent replies from appearing in the returned timeline. Setting this to "No" will mean you will receive up-to count tweets — this is because the count parameter retrieves that many tweets before filtering out retweets and replies.', 'twitterply' ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="submit">
                    <input type="submit" name="save" class="button button-primary button-large" id="save" accesskey="p" value="<?php _e( 'Save Settings', 'twitterply' ); ?>">
                </p>
            </div>
        </form>
    </div>
    <?php

    }

    /**
     * Formats tweet text to add URLs and hashtags
     *
     * @since 1.0
     */
    public function format_tweet( $text ) {
        $text = preg_replace( "#(^|[\n ])([\w]+?://[\w]+[^ \"\n\r\t< ]*)#", "\\1<a href=\"\\2\" target=\"_blank\">\\2</a>", $text );
        $text = preg_replace( "#(^|[\n ])((www|ftp)\.[^ \"\t\n\r< ]*)#", "\\1<a href=\"http://\\2\" target=\"_blank\">\\2</a>", $text );
        $text = preg_replace( "/@(\w+)/", "<a href=\"http://www.twitter.com/\\1\" target=\"_blank\">@\\1</a>", $text );
        $text = preg_replace( "/#(\w+)/", "<a href=\"http://twitter.com/search?q=%23\\1&src=hash\" target=\"_blank\">#\\1</a>", $text );
        return $text;
    }

    /**
     * Gets the tweets
     *
     * @since 1.0
     */
    public function get() {

        /** Get settings */
        $settings = $this->validate_settings( get_option( 'twitterply_settings' ) );

        /** Merge arugments with defaults */
        $args = apply_filters( 'twitterply_args', array(
            'screen_name' => $settings['screen_name'],
            'count' => $settings['count'],
            'include_rts' => $settings['include_rts'],
            'exclude_replies' => $settings['exclude_replies']
        ) );

        /** Get tweets from transient. False if it has expired */
        $tweets = get_transient( "twitterply_tweets" );
        if ( $tweets === false ) {

            /** Require the twitter auth class */
            if ( !class_exists('TwitterOAuth') )
                require_once 'includes/Twitter/twitteroauth/twitteroauth.php';

            /** Get Twitter connection */
            $twitterConnection = new TwitterOAuth(
                $settings['consumer_key'],
                $settings['consumer_secret'],
                $settings['access_token'],
                $settings['access_token_secret']
            );

            /** Get tweets */
            $tweets = $twitterConnection->get(
                'statuses/user_timeline',
                $args
            );

            /** Bail if failed */
            if ( !$tweets || isset( $tweets->errors ) )
                return false;

            /** Set tweets */
            set_transient( "twitterply_tweets", $tweets, apply_filters( 'twitterply_refresh_timeout', self::$refresh ) );

        }

        /** Return tweets */
        return $tweets;

    }

    /**
     * Prints the tweets
     *
     * @since 1.0
     */
    public function show() {

        /** Get the tweets */
        $tweets = $this->get();

        /** Bail if there are no tweets */
        if ( !$tweets ) {
            if ( current_user_can( 'edit_plugins' ) )
                echo '<p style="color: red;">'. __( 'No tweets found. Please make sure your settings are correct.', 'twitterply' ) .'</p>';
            return;
        }

        /** Print the tweets */
        foreach ( $tweets as $tweet ) {

            if ( has_action( 'twitterply_tweet_template' ) ) :

                /** Execute action that should print the tweet template */
                do_action( 'twitterply_tweet_template', $tweet );

            else :

                /** Set the date and time format */
                $datetime_format = apply_filters( 'twitterply_datetime_format', "l M j \- g:ia" );

                /** Get the date and time posted as a nice string */
                $posted_since = apply_filters( 'twitterply_posted_since', date_i18n( $datetime_format , strtotime( $tweet->created_at ) ) );

                /** Filter for linking dates to the tweet itself */
                $link_date = apply_filters( 'twitterply_link_date_to_tweet', __return_false() );
                if ( $link_date )
                    $posted_since = "<a href=\"https://twitter.com/{$tweet->user->screen_name}/status/{$tweet->id_str}\">{$posted_since}</a>";

                /** Print tweet */
                echo "<p>{$this->format_tweet( $tweet->text )}<br /><small class=\"muted\">- {$posted_since}</small></p>";

            endif;
        }

    }

}

/**
 * "Twitter Feed" WordPress widget
 *
 * @author Matthew Ruddy
 * @since 1.0
 */
add_action( 'widgets_init', create_function( '', 'register_widget( "DT_Widget" );' ) );
class DT_Widget extends WP_Widget {

    /**
     * Constructor
     *
     * @since 1.0
     */
    public function __construct() {
        parent::__construct(
            'twitterplywidget',
            __( 'Tweets', 'twitterply' ),
            array( 'description' => __( 'Display your Twitter feed.', 'twitterply' ) )
        );
    }

    /**
     * Widget logic
     *
     * @since 1.0
     */
    public function widget( $args, $instance ) {

        /** Extract arguments */
        extract( $args );

        /** Get widget title */
        $title = apply_filters( 'widgets_title', $instance['title'] );

        /** Display widget header */
        echo $before_widget;
        if ( !empty( $title ) )
            echo $before_title . $title . $after_title;
        
        /** Display tweets */
        if ( function_exists( 'twitterply' ) )
            twitterply();

        /** Display widget footer */
        echo $after_widget;


    }

    /**
     * Returns updated settings array. Also does some sanatization.
     *
     * @since 1.0
     */
    public function update( $new_instance, $old_instance ) {
        return array(
            'title' => strip_tags( $new_instance['title'] )
        );
    }

    /**
     * Widget settings form
     *
     * @since 1.0
     */
    public function form( $instance ) {
        ?>
        <p>
            <label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title', 'twitterply' ); ?></label>
            <input type="text" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" class="widefat" value="<?php if ( isset( $instance['title'] ) ) echo esc_attr( $instance['title'] ); ?>">
        </p>
        <?php
    }

}

/**
 * Helper function for displaying tweets
 *
 * @author Matthew Ruddy
 * @since 1.0
 */
if ( !function_exists( 'twitterply' ) ) {
    function twitterply() {
        Twitterply::get_instance()->show();
    }
}
