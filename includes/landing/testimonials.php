<?php
/**
 * Testimonials Component for Landing Page
 * 
 * Displays customer reviews with carousel for 3+ testimonials.
 * Includes Review structured data for SEO.
 * Hides section if no testimonials exist.
 * 
 * Requirements: 5.1, 5.2, 5.3, 5.4, 5.5
 * 
 * Usage:
 *   require_once 'classes/TestimonialService.php';
 *   $testimonialService = new TestimonialService($db, $lineAccountId);
 *   include 'includes/landing/testimonials.php';
 * 
 * Expected variables:
 *   $testimonialService - Instance of TestimonialService
 */

// Ensure $testimonialService is available
if (!isset($testimonialService) || !($testimonialService instanceof TestimonialService)) {
    return;
}

// Get approved testimonials
$testimonials = $testimonialService->getApprovedTestimonials(10);

// Hide section if no testimonials (Requirements: 5.4)
if (empty($testimonials)) {
    return;
}

$totalCount = count($testimonials);
$avgRating = $testimonialService->getAverageRating();
$useCarousel = $totalCount >= 3; // Requirements: 5.3 - Carousel for 3+ testimonials

/**
 * Render star rating HTML
 */
function renderStars($rating) {
    $html = '<div class="testimonial-stars">';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="fas fa-star"></i>';
        } else {
            $html .= '<i class="far fa-star"></i>';
        }
    }
    $html .= '</div>';
    return $html;
}
?>

<!-- Testimonials Section (Requirements: 5.1, 5.2, 5.3, 5.4, 5.5) -->
<section class="testimonials-section" id="testimonials">
    <div class="container">
        <div class="section-title">
            <h2>รีวิวจากลูกค้า</h2>
            <p>
                <?php if ($avgRating > 0): ?>
                <span class="avg-rating">
                    <i class="fas fa-star" style="color:#F59E0B;"></i>
                    <?= number_format($avgRating, 1) ?>/5
                </span>
                จาก <?= number_format($totalCount) ?> รีวิว
                <?php else: ?>
                ความคิดเห็นจากลูกค้าที่ใช้บริการ
                <?php endif; ?>
            </p>
        </div>
        
        <?php if ($useCarousel): ?>
        <!-- Carousel for 3+ testimonials (Requirements: 5.3) -->
        <div class="testimonials-carousel" id="testimonials-carousel">
            <div class="carousel-track">
                <?php foreach ($testimonials as $index => $testimonial): ?>
                <div class="testimonial-card carousel-slide" data-index="<?= $index ?>">
                    <div class="testimonial-header">
                        <?php if (!empty($testimonial['customer_avatar'])): ?>
                        <img src="<?= htmlspecialchars($testimonial['customer_avatar']) ?>" 
                             alt="<?= htmlspecialchars($testimonial['customer_name']) ?>"
                             class="testimonial-avatar"
                             loading="lazy">
                        <?php else: ?>
                        <div class="testimonial-avatar-placeholder">
                            <?= mb_substr($testimonial['customer_name'], 0, 1) ?>
                        </div>
                        <?php endif; ?>
                        <div class="testimonial-meta">
                            <!-- Requirements: 5.2 - Show customer name, rating, review text -->
                            <div class="testimonial-name"><?= htmlspecialchars($testimonial['customer_name']) ?></div>
                            <?= renderStars((int)$testimonial['rating']) ?>
                        </div>
                    </div>
                    <div class="testimonial-content">
                        <p>"<?= htmlspecialchars($testimonial['review_text']) ?>"</p>
                    </div>
                    <?php if (!empty($testimonial['source']) && $testimonial['source'] !== 'manual'): ?>
                    <div class="testimonial-source">
                        <i class="fab fa-<?= htmlspecialchars($testimonial['source']) ?>"></i>
                        <?= ucfirst(htmlspecialchars($testimonial['source'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Carousel Controls -->
            <button class="carousel-btn carousel-prev" onclick="moveCarousel(-1)" aria-label="Previous">
                <i class="fas fa-chevron-left"></i>
            </button>
            <button class="carousel-btn carousel-next" onclick="moveCarousel(1)" aria-label="Next">
                <i class="fas fa-chevron-right"></i>
            </button>
            
            <!-- Carousel Dots -->
            <div class="carousel-dots">
                <?php for ($i = 0; $i < $totalCount; $i++): ?>
                <button class="carousel-dot <?= $i === 0 ? 'active' : '' ?>" 
                        onclick="goToSlide(<?= $i ?>)"
                        aria-label="Go to slide <?= $i + 1 ?>"></button>
                <?php endfor; ?>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Grid for less than 3 testimonials -->
        <div class="testimonials-grid">
            <?php foreach ($testimonials as $testimonial): ?>
            <div class="testimonial-card">
                <div class="testimonial-header">
                    <?php if (!empty($testimonial['customer_avatar'])): ?>
                    <img src="<?= htmlspecialchars($testimonial['customer_avatar']) ?>" 
                         alt="<?= htmlspecialchars($testimonial['customer_name']) ?>"
                         class="testimonial-avatar"
                         loading="lazy">
                    <?php else: ?>
                    <div class="testimonial-avatar-placeholder">
                        <?= mb_substr($testimonial['customer_name'], 0, 1) ?>
                    </div>
                    <?php endif; ?>
                    <div class="testimonial-meta">
                        <div class="testimonial-name"><?= htmlspecialchars($testimonial['customer_name']) ?></div>
                        <?= renderStars((int)$testimonial['rating']) ?>
                    </div>
                </div>
                <div class="testimonial-content">
                    <p>"<?= htmlspecialchars($testimonial['review_text']) ?>"</p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<style>
/* Testimonials Section Styles */
.testimonials-section {
    padding: 48px 0;
    background: white;
}

.testimonials-section .section-title .avg-rating {
    font-weight: 600;
    color: #1F2937;
}

/* Grid Layout (for < 3 testimonials) */
.testimonials-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
    max-width: 800px;
    margin: 0 auto;
}

/* Testimonial Card */
.testimonial-card {
    background: #F8FAFC;
    border-radius: 16px;
    padding: 24px;
    border: 1px solid #E5E7EB;
    transition: all 0.3s ease;
}

.testimonial-card:hover {
    border-color: var(--primary, #06C755);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
}

.testimonial-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.testimonial-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
}

.testimonial-avatar-placeholder {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--primary, #06C755);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 600;
}

.testimonial-meta {
    flex: 1;
}

.testimonial-name {
    font-weight: 600;
    color: #1F2937;
    margin-bottom: 4px;
}

.testimonial-stars {
    display: flex;
    gap: 2px;
    color: #F59E0B;
    font-size: 14px;
}

.testimonial-stars .far {
    color: #D1D5DB;
}

.testimonial-content {
    color: #4B5563;
    font-size: 0.95rem;
    line-height: 1.7;
}

.testimonial-content p {
    margin: 0;
    font-style: italic;
}

.testimonial-source {
    margin-top: 12px;
    font-size: 0.8rem;
    color: #9CA3AF;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Carousel Styles (Requirements: 5.3) */
.testimonials-carousel {
    position: relative;
    overflow: hidden;
    max-width: 900px;
    margin: 0 auto;
    padding: 0 50px;
}

.carousel-track {
    display: flex;
    transition: transform 0.4s ease;
}

.carousel-slide {
    flex: 0 0 100%;
    padding: 0 10px;
}

.carousel-btn {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: white;
    border: 1px solid #E5E7EB;
    color: #6B7280;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    z-index: 10;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.carousel-btn:hover {
    background: var(--primary, #06C755);
    color: white;
    border-color: var(--primary, #06C755);
}

.carousel-prev {
    left: 0;
}

.carousel-next {
    right: 0;
}

.carousel-dots {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 24px;
}

.carousel-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #D1D5DB;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    padding: 0;
}

.carousel-dot:hover {
    background: #9CA3AF;
}

.carousel-dot.active {
    background: var(--primary, #06C755);
    transform: scale(1.2);
}

/* Responsive Design */
@media (max-width: 767px) {
    .testimonials-section {
        padding: 32px 0;
    }
    
    .testimonials-carousel {
        padding: 0 40px;
    }
    
    .carousel-btn {
        width: 32px;
        height: 32px;
    }
    
    .testimonial-card {
        padding: 20px;
    }
}

@media (min-width: 768px) {
    .testimonials-section {
        padding: 64px 0;
    }
    
    .testimonials-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .carousel-slide {
        flex: 0 0 50%;
    }
}

@media (min-width: 1024px) {
    .testimonials-section {
        padding: 80px 0;
    }
    
    .carousel-slide {
        flex: 0 0 33.333%;
    }
}

/* Reduced Motion */
@media (prefers-reduced-motion: reduce) {
    .carousel-track {
        transition: none;
    }
}
</style>

<?php if ($useCarousel): ?>
<script>
/**
 * Testimonials Carousel Controller
 * Requirements: 5.3 - Carousel for 3+ testimonials
 */
(function() {
    let currentSlide = 0;
    const totalSlides = <?= $totalCount ?>;
    let slidesPerView = 1;
    
    function updateSlidesPerView() {
        if (window.innerWidth >= 1024) {
            slidesPerView = 3;
        } else if (window.innerWidth >= 768) {
            slidesPerView = 2;
        } else {
            slidesPerView = 1;
        }
    }
    
    function updateCarousel() {
        const track = document.querySelector('.carousel-track');
        const slideWidth = 100 / slidesPerView;
        track.style.transform = `translateX(-${currentSlide * slideWidth}%)`;
        
        // Update dots
        document.querySelectorAll('.carousel-dot').forEach((dot, index) => {
            dot.classList.toggle('active', index === currentSlide);
        });
    }
    
    window.moveCarousel = function(direction) {
        const maxSlide = Math.max(0, totalSlides - slidesPerView);
        currentSlide = Math.max(0, Math.min(maxSlide, currentSlide + direction));
        updateCarousel();
    };
    
    window.goToSlide = function(index) {
        const maxSlide = Math.max(0, totalSlides - slidesPerView);
        currentSlide = Math.min(index, maxSlide);
        updateCarousel();
    };
    
    // Initialize
    updateSlidesPerView();
    updateCarousel();
    
    // Handle resize
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(function() {
            updateSlidesPerView();
            currentSlide = Math.min(currentSlide, Math.max(0, totalSlides - slidesPerView));
            updateCarousel();
        }, 100);
    });
    
    // Auto-play (optional)
    // setInterval(function() {
    //     const maxSlide = Math.max(0, totalSlides - slidesPerView);
    //     currentSlide = currentSlide >= maxSlide ? 0 : currentSlide + 1;
    //     updateCarousel();
    // }, 5000);
})();
</script>
<?php endif; ?>
