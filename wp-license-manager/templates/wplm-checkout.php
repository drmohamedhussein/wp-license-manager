<?php
/**
 * Default Checkout Template for WP License Manager
 *
 * This template is used as a fallback for the [wplm_checkout] shortcode.
 * You can override this template by creating a file named `wplm-checkout.php`
 * in your active theme's root directory.
 *
 * @package WPLM
 * @subpackage Templates
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get header
get_header();

?>

<div id="primary" class="content-area">
	<main id="main" class="site-main" role="main">

		<?php
			while ( have_posts() ) : the_post();

				// Display page content
				the_content();

				// Display the WPLM checkout shortcode content
				echo do_shortcode( '[wplm_checkout]' );

			endwhile; // End of the loop.
		?>

	</main><!-- #main -->
</div><!-- #primary -->

<?php
// Get footer
get_footer();
