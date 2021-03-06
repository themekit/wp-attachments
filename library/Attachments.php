<?php namespace Mosaicpro\WP\Plugins\Attachments;

use Mosaicpro\HtmlGenerators\Button\Button;
use Mosaicpro\HtmlGenerators\Core\IoC;
use Mosaicpro\WpCore\CRUD;
use Mosaicpro\WpCore\MetaBox;
use Mosaicpro\WpCore\PluginGeneric;
use Mosaicpro\WpCore\ThickBox;
use Mosaicpro\WpCore\Utility;
use ZipArchive;

/**
 * Class Attachments
 * @package Mosaicpro\WP\Plugins\Attachments
 */
class Attachments extends PluginGeneric
{
    /**
     * Holds an Attachments instance
     * @var
     */
    protected static $instance;

    /**
     * Holds the post types that have attachments
     * @var array
     */
    protected $post_types = [];

    /**
     * Initialize the plugin
     */
    public static function init()
    {
        $instance = self::getInstance();

        // i18n
        $instance->loadTextDomain();

        // Load Plugin Templates into the current Theme
        $instance->plugin->initPluginTemplates();

        // Initialize Attachments Widgets
        $instance->initWidgets();

        // Initialize Attachments Shortcodes
        $instance->initShortcodes();

        // Get the Container from IoC
        $app = IoC::getContainer();

        // Bind the Attachments to the Container
        $app->bindShared('attachments', function() use ($instance)
        {
            return $instance;
        });
    }

    /**
     * Get a Singleton instance of Attachments
     * @return static
     */
    public static function getInstance()
    {
        if (is_null(self::$instance))
        {
            self::$instance = new static();
        }

        return self::$instance;
    }

    /**
     * Initialize Attachments Widgets
     */
    private function initWidgets()
    {
        add_action('widgets_init', function()
        {
            $widgets = [ 'Download_Attachments' ];
            foreach ($widgets as $widget)
            {
                require_once realpath(__DIR__) . '/Widgets/' . $widget . '.php';
                register_widget(__NAMESPACE__ . '\\' . $widget . '_Widget');
            }
        });
    }

    /**
     * Initialize Attachments Shortcodes
     */
    private function initShortcodes()
    {
        add_action('init', function()
        {
            $shortcodes = [ 'Download_Attachments' ];
            foreach ($shortcodes as $sc)
            {
                require_once realpath(__DIR__) . '/Shortcodes/' . $sc . '.php';
                forward_static_call([__NAMESPACE__ . '\\' . $sc . '_Shortcode', 'init']);
            }
        });
    }

    /**
     * Register Attachments for provided post types
     * @param $post_types
     */
    public function register($post_types)
    {
        $this->post_types = array_merge($this->post_types, $post_types);

        // create relationships
        $this->crud();

        // create metaboxes
        $this->metaboxes();
    }

    /**
     * Create CRUD Relationships
     */
    private function crud()
    {
        $relation = 'attachment';
        foreach ($this->post_types as $post => $label)
        {
            CRUD::make($this->prefix, $post, $relation)
                ->setListFields($relation, $this->getListFields())
                ->setListActions($relation, ['remove_related', 'add_to_post'])
                ->setPostRelatedListActions($relation, ['sortable', 'remove_from_post'])
                ->register();
        }
    }

    /**
     * Get the CRUD List Fields
     * @return array
     */
    private function getListFields()
    {
        return [
            'ID',
            function($post)
            {
                return [
                    'field' => $this->__('Attachment'),
                    'value' => (!wp_attachment_is_image($post->ID) ? '<span class="glyphicon glyphicon-file"></span>' . PHP_EOL : '') .
                        wp_get_attachment_link($post->ID, [50, 50])
                ];
            },
            function($post)
            {
                return [
                    'field' => $this->__('Downloads'),
                    'value' => (int) get_post_meta($post->ID, '_mp_attachment_downloads', true)
                ];
            }
        ];
    }

    /**
     * Create the Meta Boxes
     */
    private function metaboxes()
    {
        foreach ($this->post_types as $post => $metabox_header)
        {
            MetaBox::make($this->prefix, 'attachments', $metabox_header)
                ->setPostType($post)
                ->setDisplay([
                    CRUD::getListContainer(['attachment']),
                    ThickBox::register_iframe( 'thickbox_attachments_list', $this->__('Assign Attachments'), 'admin-ajax.php',
                        ['action' => 'list_' . $post . '_attachment'] )->render(),
                    ThickBox::register_iframe( 'thickbox_attachments_new', $this->__('Upload'), 'media-upload.php',
                        [] )->setButtonAttributes(['class' => 'thickbox button-primary'])->render()
                ])
                ->register();
        }
    }

    /**
     * @param $attachment_id
     * @return bool|string
     */
    public function download_attachment_link($attachment_id)
    {
        if (get_post_type($attachment_id) !== 'attachment') return false;
        $title = get_the_title($attachment_id);
        $link = '<a href="' . $this->download_attachment_url($attachment_id) . '" title="' . $title . '">' . $title . '</a>';return $link;
    }

    /**
     * Get download URL for attachment
     * @param $attachment_id
     * @return string
     */
    public function download_attachment_url($attachment_id)
    {
        // $url = site_url('/'.$options['download_link'].'/'.$attachment_id.'/');
        $url = plugins_url( 'mp-attachments/download.php?id=' . $attachment_id);
        return $url;
    }

    /**
     * Get download URL for all post attachments
     * @param $post_id
     * @return string
     */
    public function download_post_attachments_url($post_id)
    {
        $url = plugins_url( 'mp-attachments/download.php?post_id=' . $post_id);
        return $url;
    }

    /**
     * Get all post attachments
     * @param $post_id
     * @return array
     */
    public function get_post_attachments($post_id)
    {
        $attachments_ids = get_post_meta($post_id, 'attachment');
        if (empty($attachments_ids)) return false;
        $attachments = get_posts([
            'post_type' => 'attachment',
            'post__in' => $attachments_ids
        ]);
        return $attachments;
    }

    /**
     * Download all attachments of a post
     * @param $post_id
     * @return bool|void
     */
    public function download_post_attachments($post_id)
    {
        $attachments = $this->get_post_attachments($post_id);
        if (!$attachments) return wp_die($this->__('The post has no attachments to download'));

        $uploads = wp_upload_dir();
        $zip = new ZipArchive();
        $zip_filename = Utility::str_random() .  '.zip';
        $zip_filepath = $uploads['basedir'] . '/' . $zip_filename;

        if ($zip->open($zip_filepath, ZipArchive::CREATE) !== true)
            return wp_die(sprintf( $this->__('cannot open <%1$s> zip file'), $zip_filepath ));

        foreach($attachments as $attachment)
        {
            $attachment = get_post_meta($attachment->ID, '_wp_attached_file', true);
            $attachment_filepath = $uploads['basedir'] . '/' . $attachment;
            $attachment_filename = $attachment;

            // no directory names
            if (($position = strrpos($attachment_filename, '/', 0)) !== false)
                $attachment_filename = substr($attachment_filename, $position + 1);

            $zip->addFile($attachment_filepath, $attachment_filename);
        }

        // close the archive
        $zip->close();

        // download the archive
        $download = $this->download_file($zip_filename, $zip_filepath);

        // remove the archive
        unlink($zip_filepath);

        // stop if the download failed
        if ($download === false) return false;

        // update attachments download count
        foreach($attachments as $attachment)
        {
            update_post_meta($attachment->ID,
                '_mp_attachment_downloads',
                (int) get_post_meta($attachment->ID, '_mp_attachment_downloads', true) + 1);
        }
    }

    /**
     * Download attachment
     * @param $attachment_id
     * @return bool
     */
    public function download_attachment($attachment_id)
    {
        if (get_post_type($attachment_id) !== 'attachment') return false;

        $uploads = wp_upload_dir();
        $attachment = get_post_meta($attachment_id, '_wp_attached_file', true);

        $filepath = $uploads['basedir'] . '/' . $attachment;
        $filename = $attachment;

        $download = $this->download_file($filename, $filepath);
        if ($download === false) return false;

        update_post_meta($attachment_id, '_mp_attachment_downloads', (int) get_post_meta($attachment_id, '_mp_attachment_downloads', true) + 1);

        exit;
    }

    /**
     * Start force download of a file
     * @param $filename
     * @param $filepath
     * @return bool
     */
    private function download_file($filename, $filepath)
    {
        if(!file_exists($filepath) || !is_readable($filepath))
            return false;

        // no directory names
        if (($position = strrpos($filename, '/', 0)) !== false)
            $filename = substr($filename, $position + 1);

        if (ini_get('zlib.output_compression'))
            ini_set('zlib.output_compression', 'Off');

        header('Content-Type: application/download');
        header('Content-Disposition: attachment; filename=' . rawurldecode($filename));
        header('Content-Transfer-Encoding: binary');
        header('Accept-Ranges: bytes');
        header('Cache-control: private');
        header('Pragma: private');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        header('Content-Length: ' . filesize($filepath));

        if ($filepath = fopen($filepath, 'r'))
        {
            while(!feof($filepath) && (!connection_aborted()))
            {
                echo($buffer = fread($filepath, 524288));
                flush();
            }

            fclose($filepath);
        }
        else return false;
    }

    /**
     * Get a list of download attachment links
     * @param $attachments
     * @param string $separator
     * @param string $before
     * @param string $after
     * @return string
     */
    public function get_download_attachments_list($attachments, $separator = '<br/>', $before = '', $after = '')
    {
        $output = [];
        foreach ($attachments as $attachment)
            $output[] = Button::success($this->__('Download') . ' ' . $attachment->post_title)
                    ->addUrl($this->download_attachment_url($attachment->ID))
                    ->isLink();

        return $before . implode($separator, $output) . $after;
    }
}