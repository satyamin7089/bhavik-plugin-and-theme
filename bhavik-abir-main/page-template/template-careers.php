<?php
/**
 * Template Name: Careers
 * Template Post Type: page
 *
 * Careers page: the shared site-wide banner (background, spacing and the
 * decorative ring come from the shared banner system in site.css via
 * .ab-legal-hero) followed by the ASRS Recruit Engine careers portal
 * rendered through the [asrs_careers] shortcode.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

$ab_careers_page_id = get_queried_object_id();
$ab_careers_title   = $ab_careers_page_id ? get_the_title( $ab_careers_page_id ) : '';
if ( '' === trim( (string) $ab_careers_title ) ) {
    $ab_careers_title = 'Careers';
}
?>
<main id="main-content" class="ab-careers-page">
    <!-- Simple banner: shared website banner background, centred heading. -->
    <section class="ab-legal-hero ab-careers-hero" aria-labelledby="ab-careers-title">
        <div class="ab-wrap">
            <span class="ab-eyebrow">Careers at Bhavik Enterprises</span>
            <h1 id="ab-careers-title"><?php echo esc_html( $ab_careers_title ); ?></h1>
            <p class="ab-careers-intro">Build your career with a polymer trading and supply team that values ownership, clear communication and long-term customer relationships. Explore the open roles below and apply online in a few minutes.</p>
        </div>
    </section>

    <section class="ab-careers-main ab-section" id="open-positions" aria-label="Open positions">
        <div class="ab-careers-backdrop" aria-hidden="true"><span></span><span></span><span></span></div>
        <div class="ab-wrap">
            <?php echo do_shortcode( '[asrs_careers]' ); ?>
        </div>
    </section>
</main>
<?php get_footer(); ?>
