/**
 * Page Transition Component
 * Handles smooth slide/fade transitions between pages
 * 
 * Requirements: 9.5, 9.6
 * - Smooth page transitions (slide or fade)
 * - Handle safe area insets
 */

class PageTransition {
    constructor(options = {}) {
        this.contentEl = null;
        this.transitionType = options.transitionType || 'slide'; // 'slide', 'fade', 'slide-up'
        this.duration = options.duration || 200;
        this.isTransitioning = false;
        
        // Check for reduced motion preference
        this.prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        
        // Listen for preference changes
        window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', (e) => {
            this.prefersReducedMotion = e.matches;
        });
    }

    /**
     * Initialize the page transition handler
     * @param {HTMLElement|string} contentEl - Content container element
     */
    init(contentEl) {
        this.contentEl = typeof contentEl === 'string' 
            ? document.querySelector(contentEl) 
            : contentEl;
        
        if (!this.contentEl) {
            console.error('PageTransition: Content element not found');
            return;
        }
        
        console.log('✅ PageTransition initialized');
    }

    /**
     * Set transition type
     * @param {string} type - 'slide', 'fade', or 'slide-up'
     */
    setTransitionType(type) {
        this.transitionType = type;
    }

    /**
     * Perform page exit transition
     * @returns {Promise} - Resolves when exit animation completes
     */
    async exit() {
        if (!this.contentEl || this.prefersReducedMotion) {
            return Promise.resolve();
        }

        this.isTransitioning = true;
        
        const exitClass = this.getExitClass();
        this.contentEl.classList.add(exitClass);
        
        return new Promise(resolve => {
            setTimeout(() => {
                resolve();
            }, this.duration * 0.75);
        });
    }

    /**
     * Perform page enter transition
     * @returns {Promise} - Resolves when enter animation completes
     */
    async enter() {
        if (!this.contentEl) {
            return Promise.resolve();
        }

        // Remove any exit classes
        this.contentEl.classList.remove('page-exit', 'page-fade-exit');
        
        if (this.prefersReducedMotion) {
            this.isTransitioning = false;
            return Promise.resolve();
        }

        const enterClass = this.getEnterClass();
        this.contentEl.classList.add(enterClass);
        
        return new Promise(resolve => {
            setTimeout(() => {
                this.contentEl.classList.remove(enterClass);
                this.isTransitioning = false;
                resolve();
            }, this.duration);
        });
    }

    /**
     * Get exit class based on transition type
     * @returns {string} - CSS class name
     */
    getExitClass() {
        switch (this.transitionType) {
            case 'fade':
                return 'page-fade-exit';
            case 'slide-up':
            case 'slide':
            default:
                return 'page-exit';
        }
    }

    /**
     * Get enter class based on transition type
     * @returns {string} - CSS class name
     */
    getEnterClass() {
        switch (this.transitionType) {
            case 'fade':
                return 'page-fade-enter';
            case 'slide-up':
                return 'page-slide-up-enter';
            case 'slide':
            default:
                return 'page-enter';
        }
    }

    /**
     * Perform a complete page transition
     * @param {Function} renderFn - Function that renders new content
     * @returns {Promise} - Resolves when transition completes
     */
    async transition(renderFn) {
        if (this.isTransitioning) {
            console.warn('PageTransition: Already transitioning');
            return;
        }

        try {
            // Exit current page
            await this.exit();
            
            // Render new content
            if (typeof renderFn === 'function') {
                const html = await renderFn();
                if (this.contentEl && html) {
                    this.contentEl.innerHTML = html;
                }
            }
            
            // Enter new page
            await this.enter();
            
            // Scroll to top
            this.scrollToTop();
            
        } catch (error) {
            console.error('PageTransition error:', error);
            this.isTransitioning = false;
            throw error;
        }
    }

    /**
     * Scroll to top of page
     */
    scrollToTop() {
        window.scrollTo({
            top: 0,
            behavior: this.prefersReducedMotion ? 'auto' : 'smooth'
        });
    }

    /**
     * Check if currently transitioning
     * @returns {boolean}
     */
    isInTransition() {
        return this.isTransitioning;
    }

    /**
     * Cancel any ongoing transition
     */
    cancel() {
        if (this.contentEl) {
            this.contentEl.classList.remove(
                'page-exit', 
                'page-enter', 
                'page-fade-exit', 
                'page-fade-enter',
                'page-slide-up-enter'
            );
        }
        this.isTransitioning = false;
    }
}

/**
 * Safe Area Handler
 * Utility for handling iOS safe area insets
 * 
 * Requirements: 9.6 - Handle safe area insets
 */
class SafeAreaHandler {
    constructor() {
        this.insets = {
            top: 0,
            bottom: 0,
            left: 0,
            right: 0
        };
        
        this.updateInsets();
        
        // Update on resize/orientation change
        window.addEventListener('resize', () => this.updateInsets());
        window.addEventListener('orientationchange', () => {
            setTimeout(() => this.updateInsets(), 100);
        });
    }

    /**
     * Update safe area inset values
     */
    updateInsets() {
        const computedStyle = getComputedStyle(document.documentElement);
        
        this.insets = {
            top: parseInt(computedStyle.getPropertyValue('--safe-area-top') || '0', 10),
            bottom: parseInt(computedStyle.getPropertyValue('--safe-area-bottom') || '0', 10),
            left: parseInt(computedStyle.getPropertyValue('--safe-area-left') || '0', 10),
            right: parseInt(computedStyle.getPropertyValue('--safe-area-right') || '0', 10)
        };
        
        // Also try to get from env() if CSS variables aren't set
        if (this.insets.bottom === 0 && document.body) {
            try {
                // Create a temporary element to measure
                const temp = document.createElement('div');
                temp.style.cssText = 'position:fixed;bottom:0;height:env(safe-area-inset-bottom,0px);visibility:hidden;';
                document.body.appendChild(temp);
                this.insets.bottom = temp.offsetHeight;
                if (temp.parentNode === document.body) {
                    document.body.removeChild(temp);
                }
            } catch (e) {
                console.warn('SafeAreaHandler: Could not measure safe area', e);
            }
        }
    }

    /**
     * Get safe area insets
     * @returns {Object} - { top, bottom, left, right }
     */
    getInsets() {
        return { ...this.insets };
    }

    /**
     * Check if device has notch/safe area
     * @returns {boolean}
     */
    hasNotch() {
        return this.insets.top > 20 || this.insets.bottom > 0;
    }

    /**
     * Apply safe area padding to an element
     * @param {HTMLElement} element - Element to apply padding to
     * @param {string} position - 'top', 'bottom', 'left', 'right', 'all'
     */
    applyPadding(element, position = 'all') {
        if (!element) return;
        
        switch (position) {
            case 'top':
                element.style.paddingTop = `${this.insets.top}px`;
                break;
            case 'bottom':
                element.style.paddingBottom = `${this.insets.bottom}px`;
                break;
            case 'left':
                element.style.paddingLeft = `${this.insets.left}px`;
                break;
            case 'right':
                element.style.paddingRight = `${this.insets.right}px`;
                break;
            case 'all':
                element.style.paddingTop = `${this.insets.top}px`;
                element.style.paddingBottom = `${this.insets.bottom}px`;
                element.style.paddingLeft = `${this.insets.left}px`;
                element.style.paddingRight = `${this.insets.right}px`;
                break;
        }
    }

    /**
     * Get bottom nav height including safe area
     * @returns {number} - Height in pixels
     */
    getBottomNavHeight() {
        const baseHeight = 56; // Base nav height
        return baseHeight + this.insets.bottom;
    }
}

// Create global instances
window.pageTransition = new PageTransition();
window.safeAreaHandler = new SafeAreaHandler();

// Auto-initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    const contentEl = document.getElementById('app-content');
    if (contentEl) {
        window.pageTransition.init(contentEl);
    }
});
