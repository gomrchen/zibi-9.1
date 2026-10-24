<?php
/**
 * zib_shop_setup - Shop module setup class (local replacement)
 * Delegates to $shop (zib_shop instance) methods defined in class.init.php
 */
class zib_shop_setup
{
    private $shop = null;

    public function __construct($shop)
    {
        $this->shop = $shop;
        $this->setup($shop);
    }

    public static function isok($code = '')
    {
        return true;
    }

    public function tool()
    {
        return true;
    }

    public function ajax()
    {
    }

    public function aut()
    {
        return true;
    }

    public function admin_action()
    {
        if (!$this->shop || !$this->shop->s) {
            return;
        }

        // Admin columns for shop products
        add_filter('manage_shop_product_posts_columns', array($this->shop, 'product_columns'));
        add_action('manage_shop_product_posts_custom_column', array($this->shop, 'product_custom_column'), 10, 2);

        // Category columns
        add_filter('manage_edit-shop_cat_columns', array($this->shop, 'cat_columns'));
        add_filter('manage_shop_cat_custom_column', array($this->shop, 'cat_custom_column'), 10, 3);

        // Tag columns
        add_filter('manage_edit-shop_tag_columns', array($this->shop, 'tag_columns'));
        add_filter('manage_shop_tag_custom_column', array($this->shop, 'tag_custom_column'), 10, 3);

        // Discount columns
        add_filter('manage_edit-shop_discount_columns', array($this->shop, 'discount_columns'));
        add_filter('manage_shop_discount_custom_column', array($this->shop, 'discount_custom_column'), 10, 3);

        // Admin post query
        add_action('pre_get_posts', array($this->shop, 'admin_post_query'));

        // Admin menu separator
        add_action('admin_menu', array($this->shop, 'admin_menu_separator'), 99);

        // Post meta initialization
        add_action('wp_insert_post', array($this->shop, 'initialization_posts_meta'), 10, 3);
        add_action('created_term', array($this->shop, 'initialization_term_meta'), 10, 4);

        // Comments columns for shop
        add_filter('manage_edit-comments_columns', array($this->shop, 'comments_columns'));
        add_action('manage_comments_custom_column', array($this->shop, 'comments_custom_column'), 10, 2);
        add_action('current_screen', array($this->shop, 'manage_comments_nav'));
    }

    public function setup($shop)
    {
        if (!$shop) {
            return;
        }

        // Register post type and taxonomies via $shop->register_post_type() in class.init.php
        $shop->register_post_type();

        if ($shop->s) {
            // Main query hooks
            add_action('pre_get_posts', array($shop, 'main_post_query'));

            // Rewrite rules
            add_action('init', array($shop, 'add_rewrite_rule'));

            // Post type link filter (URL rewriting)
            add_filter('post_type_link', array($shop, 'post_type_link'), 10, 2);

            // Query vars
            add_filter('query_vars', array($shop, 'query_vars'));

            // Redirect canonical
            add_filter('redirect_canonical', array($shop, 'redirect_canonical'));

            // Template redirect for custom pages
            add_action('template_redirect', array($shop, 'template_redirect'));
        }

        // Admin hooks
        if (is_admin()) {
            $this->admin_action();

            if ($shop->s) {
                add_filter('comments_list_table_query_args', array($shop, 'comments_list_table_query_args'));
            }
        }
    }
}

function zib_shop_get_aut_time_int($data = '')
{
    return 0;
}

if (!function_exists('zib_admin_aut_add_action')) {
    function zib_admin_aut_add_action()
    {
        return true;
    }
}
