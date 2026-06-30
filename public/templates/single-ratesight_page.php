<?php
/**
 * Ratesight fallback template for ratesight_page CPT.
 * Only used when the active theme has no single-ratesight_page.php of its own.
 * Uses get_header/footer so it inherits the theme's chrome (nav, footer, etc.)
 */
defined( 'ABSPATH' ) || die;

get_header();

while ( have_posts() ) :
    the_post();

    $show_title = get_post_meta( get_the_ID(), '_rs_show_title', true );
    $show_title = ( $show_title === '' || $show_title === '1' || $show_title === true );
    ?>

    <div id="rs-page-wrap" class="rs-page entry-content" style="max-width:960px;margin:40px auto;padding:0 20px;">

        <?php if ( $show_title ) : ?>
            <h1 class="entry-title" style="margin-bottom:24px;"><?php the_title(); ?></h1>
        <?php endif; ?>

        <div class="rs-page-content">
            <?php the_content(); ?>
        </div>

    </div>

    <?php
endwhile;

get_footer();
