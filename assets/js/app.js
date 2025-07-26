// Modern Manga Website JavaScript
class MangaApp {
    constructor() {
        this.init();
        this.bindEvents();
        this.initComponents();
    }

    init() {
        // Initialize app state
        this.isLoading = false;
        this.currentPage = 1;
        this.searchTimeout = null;
        this.notifications = [];
        
        // Add loading overlay
        this.createLoadingOverlay();
        
        // Initialize lazy loading
        this.initLazyLoading();
        
        // Initialize smooth scrolling
        this.initSmoothScrolling();
    }

    bindEvents() {
        // Search functionality
        const searchInput = document.querySelector('.search-input');
        if (searchInput) {
            searchInput.addEventListener('input', this.handleSearch.bind(this));
            searchInput.addEventListener('keypress', this.handleSearchKeypress.bind(this));
        }

        // Navigation
        document.addEventListener('click', this.handleNavigation.bind(this));
        
        // Form submissions
        document.addEventListener('submit', this.handleFormSubmit.bind(this));
        
        // Like buttons
        document.addEventListener('click', this.handleLikeClick.bind(this));
        
        // Infinite scroll
        window.addEventListener('scroll', this.handleScroll.bind(this));
        
        // Keyboard shortcuts
        document.addEventListener('keydown', this.handleKeyboard.bind(this));
        
        // Mobile menu
        this.initMobileMenu();
    }

    initComponents() {
        // Initialize tooltips
        this.initTooltips();
        
        // Initialize modals
        this.initModals();
        
        // Initialize comment system
        this.initComments();
        
        // Initialize rating system
        this.initRating();
        
        // Auto-save reading progress
        this.initReadingProgress();
    }

    // Search functionality with debouncing
    handleSearch(event) {
        const query = event.target.value.trim();
        
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(() => {
            if (query.length >= 2) {
                this.performSearch(query);
            } else if (query.length === 0) {
                this.clearSearchResults();
            }
        }, 300);
    }

    handleSearchKeypress(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            const query = event.target.value.trim();
            if (query.length >= 2) {
                this.performSearch(query, true);
            }
        }
    }

    async performSearch(query, redirect = false) {
        if (redirect) {
            window.location.href = `?page=search&q=${encodeURIComponent(query)}`;
            return;
        }

        try {
            this.showLoading();
            const response = await this.fetchAPI(`/api/search?q=${encodeURIComponent(query)}`);
            this.displaySearchResults(response.data);
        } catch (error) {
            this.showNotification('Lỗi khi tìm kiếm', 'error');
        } finally {
            this.hideLoading();
        }
    }

    displaySearchResults(results) {
        const searchResults = document.getElementById('search-results');
        if (!searchResults) return;

        if (results.length === 0) {
            searchResults.innerHTML = '<div class="no-results">Không tìm thấy kết quả nào</div>';
            return;
        }

        const resultsHTML = results.map(comic => `
            <div class="search-result-item">
                <img src="${comic.thumbnail}" alt="${comic.title}" class="search-thumb">
                <div class="search-info">
                    <h4 class="search-title">${this.highlightText(comic.title, query)}</h4>
                    <p class="search-author">${comic.author}</p>
                    <div class="search-meta">
                        <span class="search-rating">★ ${comic.rating}</span>
                        <span class="search-status">${comic.status}</span>
                    </div>
                </div>
            </div>
        `).join('');

        searchResults.innerHTML = resultsHTML;
    }

    highlightText(text, query) {
        const regex = new RegExp(`(${query})`, 'gi');
        return text.replace(regex, '<mark>$1</mark>');
    }

    clearSearchResults() {
        const searchResults = document.getElementById('search-results');
        if (searchResults) {
            searchResults.innerHTML = '';
        }
    }

    // Navigation with AJAX
    async handleNavigation(event) {
        const link = event.target.closest('a[data-ajax]');
        if (!link) return;

        event.preventDefault();
        const url = link.href;
        
        try {
            this.showLoading();
            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (response.ok) {
                const html = await response.text();
                this.updatePageContent(html);
                history.pushState(null, '', url);
            }
        } catch (error) {
            window.location.href = url;
        } finally {
            this.hideLoading();
        }
    }

    updatePageContent(html) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        
        // Update main content
        const newContent = doc.querySelector('.main-content');
        const currentContent = document.querySelector('.main-content');
        if (newContent && currentContent) {
            currentContent.innerHTML = newContent.innerHTML;
        }
        
        // Update title
        document.title = doc.title;
        
        // Re-initialize components
        this.initLazyLoading();
        this.initComments();
    }

    // Form handling with AJAX
    async handleFormSubmit(event) {
        const form = event.target;
        if (!form.dataset.ajax) return;

        event.preventDefault();
        
        const formData = new FormData(form);
        const submitBtn = form.querySelector('[type="submit"]');
        
        try {
            this.setButtonLoading(submitBtn, true);
            
            const response = await fetch(form.action || window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showNotification(result.message || 'Thành công!', 'success');
                if (result.redirect) {
                    setTimeout(() => window.location.href = result.redirect, 1000);
                }
                if (result.reload) {
                    setTimeout(() => window.location.reload(), 1000);
                }
            } else {
                this.showNotification(result.message || 'Có lỗi xảy ra!', 'error');
            }
        } catch (error) {
            this.showNotification('Lỗi kết nối!', 'error');
        } finally {
            this.setButtonLoading(submitBtn, false);
        }
    }

    // Like system
    async handleLikeClick(event) {
        if (!event.target.matches('.like-btn, .like-btn *')) return;
        
        const likeBtn = event.target.closest('.like-btn');
        if (!likeBtn) return;
        
        event.preventDefault();
        
        const type = likeBtn.dataset.type; // comment, comic, etc.
        const id = likeBtn.dataset.id;
        
        try {
            const response = await this.fetchAPI('/api/like', {
                method: 'POST',
                body: JSON.stringify({ type, id }),
                headers: { 'Content-Type': 'application/json' }
            });
            
            if (response.success) {
                this.updateLikeButton(likeBtn, response.liked, response.count);
            }
        } catch (error) {
            this.showNotification('Lỗi khi thực hiện thao tác', 'error');
        }
    }

    updateLikeButton(button, liked, count) {
        const icon = button.querySelector('.like-icon');
        const counter = button.querySelector('.like-count');
        
        button.classList.toggle('liked', liked);
        if (counter) counter.textContent = count;
        
        // Animate
        button.style.transform = 'scale(1.2)';
        setTimeout(() => button.style.transform = 'scale(1)', 150);
    }

    // Infinite scroll
    handleScroll() {
        if (this.isLoading) return;
        
        const scrollPosition = window.innerHeight + window.scrollY;
        const documentHeight = document.documentElement.offsetHeight;
        
        if (scrollPosition >= documentHeight - 1000) {
            this.loadMoreContent();
        }
    }

    async loadMoreContent() {
        const loadMoreBtn = document.querySelector('.load-more-btn');
        if (!loadMoreBtn || loadMoreBtn.style.display === 'none') return;
        
        this.isLoading = true;
        this.currentPage++;
        
        try {
            const response = await this.fetchAPI(`/api/comics?page=${this.currentPage}`);
            this.appendComics(response.data);
            
            if (!response.hasMore) {
                loadMoreBtn.style.display = 'none';
            }
        } catch (error) {
            this.showNotification('Lỗi khi tải thêm nội dung', 'error');
            this.currentPage--;
        } finally {
            this.isLoading = false;
        }
    }

    appendComics(comics) {
        const comicGrid = document.querySelector('.comic-grid');
        if (!comicGrid) return;
        
        const comicsHTML = comics.map(comic => this.createComicCard(comic)).join('');
        comicGrid.insertAdjacentHTML('beforeend', comicsHTML);
        
        // Initialize lazy loading for new images
        this.initLazyLoading();
    }

    createComicCard(comic) {
        return `
            <div class="comic-card" data-aos="fade-up">
                <a href="?page=comic&id=${comic.id}" class="comic-link">
                    <div class="comic-thumbnail">
                        <img data-src="${comic.thumbnail}" alt="${comic.title}" class="lazy-load">
                        <div class="comic-status status-${comic.status}">${comic.status_text}</div>
                    </div>
                    <div class="comic-info">
                        <h3 class="comic-title">${comic.title}</h3>
                        <div class="comic-meta">
                            <div class="comic-rating">
                                <span class="rating-star">★</span>
                                <span>${comic.rating}</span>
                            </div>
                            <span class="comic-views">${this.formatNumber(comic.views)} views</span>
                        </div>
                    </div>
                </a>
            </div>
        `;
    }

    // Lazy loading for images
    initLazyLoading() {
        const images = document.querySelectorAll('img[data-src]:not(.loaded)');
        
        if ('IntersectionObserver' in window) {
            const imageObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        this.loadImage(img);
                        imageObserver.unobserve(img);
                    }
                });
            });
            
            images.forEach(img => imageObserver.observe(img));
        } else {
            // Fallback for older browsers
            images.forEach(img => this.loadImage(img));
        }
    }

    loadImage(img) {
        const placeholder = img.src || 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjI3MCIgdmlld0JveD0iMCAwIDIwMCAyNzAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PC9zdmc+';
        
        img.style.background = 'linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%)';
        img.style.backgroundSize = '200% 100%';
        img.style.animation = 'loading 1.5s infinite';
        
        const tempImg = new Image();
        tempImg.onload = () => {
            img.src = tempImg.src;
            img.classList.add('loaded');
            img.style.background = '';
            img.style.animation = '';
        };
        tempImg.onerror = () => {
            img.src = placeholder;
            img.classList.add('loaded', 'error');
        };
        tempImg.src = img.dataset.src;
    }

    // Smooth scrolling
    initSmoothScrolling() {
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', (e) => {
                e.preventDefault();
                const target = document.querySelector(anchor.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    }

    // Keyboard shortcuts
    handleKeyboard(event) {
        // Ctrl/Cmd + K for search
        if ((event.ctrlKey || event.metaKey) && event.key === 'k') {
            event.preventDefault();
            const searchInput = document.querySelector('.search-input');
            if (searchInput) {
                searchInput.focus();
            }
        }
        
        // Escape to close modals
        if (event.key === 'Escape') {
            this.closeAllModals();
        }
        
        // Arrow keys for chapter navigation
        if (document.body.classList.contains('reading-page')) {
            if (event.key === 'ArrowLeft') {
                const prevBtn = document.querySelector('.prev-chapter');
                if (prevBtn) prevBtn.click();
            }
            if (event.key === 'ArrowRight') {
                const nextBtn = document.querySelector('.next-chapter');
                if (nextBtn) nextBtn.click();
            }
        }
    }

    // Comment system
    initComments() {
        // Reply buttons
        document.querySelectorAll('.reply-btn').forEach(btn => {
            btn.addEventListener('click', this.handleReplyClick.bind(this));
        });
        
        // Load more comments
        const loadMoreComments = document.querySelector('.load-more-comments');
        if (loadMoreComments) {
            loadMoreComments.addEventListener('click', this.loadMoreComments.bind(this));
        }
    }

    handleReplyClick(event) {
        event.preventDefault();
        const commentId = event.target.dataset.commentId;
        const replyForm = document.querySelector(`#reply-form-${commentId}`);
        
        if (replyForm) {
            replyForm.style.display = replyForm.style.display === 'none' ? 'block' : 'none';
            if (replyForm.style.display === 'block') {
                const textarea = replyForm.querySelector('textarea');
                if (textarea) textarea.focus();
            }
        }
    }

    async loadMoreComments() {
        const commentsContainer = document.querySelector('.comments-container');
        const currentCount = commentsContainer.querySelectorAll('.comment-item').length;
        
        try {
            this.showLoading();
            const response = await this.fetchAPI(`/api/comments?offset=${currentCount}`);
            
            response.comments.forEach(comment => {
                commentsContainer.insertAdjacentHTML('beforeend', this.createCommentHTML(comment));
            });
            
            if (!response.hasMore) {
                document.querySelector('.load-more-comments').style.display = 'none';
            }
        } catch (error) {
            this.showNotification('Lỗi khi tải bình luận', 'error');
        } finally {
            this.hideLoading();
        }
    }

    // Rating system
    initRating() {
        document.querySelectorAll('.rating-stars').forEach(container => {
            const stars = container.querySelectorAll('.star');
            const input = container.querySelector('input[type="hidden"]');
            
            stars.forEach((star, index) => {
                star.addEventListener('click', () => {
                    const rating = index + 1;
                    this.setRating(container, rating);
                    if (input) input.value = rating;
                    
                    // Submit rating
                    this.submitRating(container.dataset.comicId, rating);
                });
                
                star.addEventListener('mouseenter', () => {
                    this.highlightStars(stars, index + 1);
                });
            });
            
            container.addEventListener('mouseleave', () => {
                const currentRating = input ? input.value : 0;
                this.highlightStars(stars, currentRating);
            });
        });
    }

    setRating(container, rating) {
        const stars = container.querySelectorAll('.star');
        this.highlightStars(stars, rating);
    }

    highlightStars(stars, rating) {
        stars.forEach((star, index) => {
            star.classList.toggle('active', index < rating);
        });
    }

    async submitRating(comicId, rating) {
        try {
            const response = await this.fetchAPI('/api/rate', {
                method: 'POST',
                body: JSON.stringify({ comic_id: comicId, rating }),
                headers: { 'Content-Type': 'application/json' }
            });
            
            if (response.success) {
                this.showNotification('Đã đánh giá thành công!', 'success');
            }
        } catch (error) {
            this.showNotification('Lỗi khi đánh giá', 'error');
        }
    }

    // Reading progress
    initReadingProgress() {
        if (!document.body.classList.contains('reading-page')) return;
        
        let progressTimer;
        const chapterId = document.body.dataset.chapterId;
        
        const saveProgress = () => {
            const scrollPercent = (window.scrollY / (document.documentElement.scrollHeight - window.innerHeight)) * 100;
            localStorage.setItem(`progress_${chapterId}`, scrollPercent);
        };
        
        window.addEventListener('scroll', () => {
            clearTimeout(progressTimer);
            progressTimer = setTimeout(saveProgress, 500);
        });
        
        // Restore progress
        const savedProgress = localStorage.getItem(`progress_${chapterId}`);
        if (savedProgress && savedProgress > 10) {
            setTimeout(() => {
                const scrollTo = (document.documentElement.scrollHeight - window.innerHeight) * (savedProgress / 100);
                window.scrollTo({ top: scrollTo, behavior: 'smooth' });
            }, 1000);
        }
    }

    // Mobile menu
    initMobileMenu() {
        const menuToggle = document.querySelector('.menu-toggle');
        const mobileMenu = document.querySelector('.mobile-menu');
        
        if (menuToggle && mobileMenu) {
            menuToggle.addEventListener('click', () => {
                mobileMenu.classList.toggle('active');
                menuToggle.classList.toggle('active');
            });
            
            // Close on outside click
            document.addEventListener('click', (e) => {
                if (!mobileMenu.contains(e.target) && !menuToggle.contains(e.target)) {
                    mobileMenu.classList.remove('active');
                    menuToggle.classList.remove('active');
                }
            });
        }
    }

    // Tooltips
    initTooltips() {
        document.querySelectorAll('[data-tooltip]').forEach(element => {
            element.addEventListener('mouseenter', this.showTooltip.bind(this));
            element.addEventListener('mouseleave', this.hideTooltip.bind(this));
        });
    }

    showTooltip(event) {
        const element = event.target;
        const text = element.dataset.tooltip;
        
        const tooltip = document.createElement('div');
        tooltip.className = 'tooltip';
        tooltip.textContent = text;
        document.body.appendChild(tooltip);
        
        const rect = element.getBoundingClientRect();
        tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
        tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';
        
        element._tooltip = tooltip;
    }

    hideTooltip(event) {
        const tooltip = event.target._tooltip;
        if (tooltip) {
            tooltip.remove();
            delete event.target._tooltip;
        }
    }

    // Modals
    initModals() {
        document.querySelectorAll('[data-modal]').forEach(trigger => {
            trigger.addEventListener('click', (e) => {
                e.preventDefault();
                const modalId = trigger.dataset.modal;
                this.openModal(modalId);
            });
        });
        
        document.querySelectorAll('.modal-close').forEach(closeBtn => {
            closeBtn.addEventListener('click', () => {
                this.closeAllModals();
            });
        });
        
        // Close on backdrop click
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-backdrop')) {
                this.closeAllModals();
            }
        });
    }

    openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('active');
            document.body.classList.add('modal-open');
        }
    }

    closeAllModals() {
        document.querySelectorAll('.modal.active').forEach(modal => {
            modal.classList.remove('active');
        });
        document.body.classList.remove('modal-open');
    }

    // Utility functions
    createLoadingOverlay() {
        const overlay = document.createElement('div');
        overlay.id = 'loading-overlay';
        overlay.innerHTML = `
            <div class="loading-spinner-container">
                <div class="loading-spinner"></div>
                <div class="loading-text">Đang tải...</div>
            </div>
        `;
        overlay.style.display = 'none';
        document.body.appendChild(overlay);
    }

    showLoading() {
        const overlay = document.getElementById('loading-overlay');
        if (overlay) {
            overlay.style.display = 'flex';
        }
    }

    hideLoading() {
        const overlay = document.getElementById('loading-overlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    }

    setButtonLoading(button, loading) {
        if (!button) return;
        
        if (loading) {
            button.disabled = true;
            button.dataset.originalText = button.textContent;
            button.innerHTML = '<span class="spinner"></span> Đang xử lý...';
        } else {
            button.disabled = false;
            button.textContent = button.dataset.originalText || 'Submit';
        }
    }

    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.textContent = message;
        
        // Add to notifications container or create one
        let container = document.querySelector('.notifications-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'notifications-container';
            container.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 10000;
                pointer-events: none;
            `;
            document.body.appendChild(container);
        }
        
        container.appendChild(notification);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            notification.remove();
        }, 5000);
        
        // Remove on click
        notification.style.pointerEvents = 'auto';
        notification.style.cursor = 'pointer';
        notification.addEventListener('click', () => notification.remove());
    }

    async fetchAPI(url, options = {}) {
        const defaultOptions = {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content
            }
        };
        
        const response = await fetch(url, { ...defaultOptions, ...options });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return await response.json();
    }

    formatNumber(num) {
        if (num >= 1000000) {
            return (num / 1000000).toFixed(1) + 'M';
        }
        if (num >= 1000) {
            return (num / 1000).toFixed(1) + 'K';
        }
        return num.toString();
    }

    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    throttle(func, limit) {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        }
    }
}

// Initialize the app when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new MangaApp();
});

// Service Worker for offline functionality (optional)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then((registration) => {
                console.log('SW registered: ', registration);
            })
            .catch((registrationError) => {
                console.log('SW registration failed: ', registrationError);
            });
    });
}