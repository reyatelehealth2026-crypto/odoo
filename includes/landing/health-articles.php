<?php
/**
 * Health Articles Component - Landing Page
 * แสดงบทความสุขภาพล่าสุด
 */

require_once __DIR__ . '/../../classes/HealthArticleService.php';
$articleService = new HealthArticleService($db, $lineAccountId);
$articles = $articleService->getPublishedArticles(6);

if (empty($articles)) return;
?>

<!-- Health Articles Section -->
<section class="health-articles-section" id="health-articles">
    <div class="container">
        <div class="section-title health-articles-head">
            <h2>บทความดีๆ มีประโยชน์ต่อการดูแลสุขภาพ</h2>
            <p>นอกจากเราจะมีบริการให้คำปรึกษาทางการแพทย์ และเป็นร้านขายยาที่จัดส่ง Delivery แล้ว เรายังเป็นแหล่งความรู้ดีๆ ที่จะช่วยให้คุณรู้และเข้าใจวิธีการเลือกใช้ยาอย่างปลอดภัย และดูแลสุขภาพร่างกายของตนเองได้อย่างเหมาะสม</p>
        </div>
        
        <div class="articles-grid">
            <?php foreach ($articles as $article): ?>
            <a href="article.php?slug=<?= htmlspecialchars($article['slug']) ?>" class="article-card">
                <div class="article-image">
                    <?php if (!empty($article['featured_image'])): ?>
                    <img src="<?= htmlspecialchars($article['featured_image']) ?>" 
                         alt="<?= htmlspecialchars($article['title']) ?>"
                         loading="lazy">
                    <?php else: ?>
                    <div class="article-placeholder">
                        <i class="fas fa-newspaper" aria-hidden="true"></i>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($article['category_name'])): ?>
                    <span class="article-category"><?= htmlspecialchars($article['category_name']) ?></span>
                    <?php endif; ?>
                    
                    <?php if ($article['is_featured']): ?>
                    <span class="article-badge">แนะนำ</span>
                    <?php endif; ?>
                </div>
                
                <div class="article-content">
                    <h3 class="article-title"><?= htmlspecialchars($article['title']) ?></h3>
                    
                    <?php if (!empty($article['excerpt'])): ?>
                    <p class="article-excerpt"><?= htmlspecialchars(mb_substr($article['excerpt'], 0, 100)) ?>...</p>
                    <?php endif; ?>
                    
                    <div class="article-meta">
                        <?php if (!empty($article['author_name'])): ?>
                        <span class="article-author">
                            <i class="fas fa-user-md" aria-hidden="true"></i>
                            <?= htmlspecialchars($article['author_name']) ?>
                        </span>
                        <?php endif; ?>
                        
                        <?php if (!empty($article['published_at'])): ?>
                        <span class="article-date">
                            <i class="fas fa-calendar" aria-hidden="true"></i>
                            <?php
                            $ts = strtotime($article['published_at']);
                            echo $ts ? date('d/m/Y', $ts) : '';
                            ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        
        <div class="view-all-articles">
            <a href="articles.php" class="btn btn-outline-primary view-all-articles-btn">
                <i class="fas fa-book-open" aria-hidden="true"></i>
                ดูบทความทั้งหมด
            </a>
        </div>
    </div>
</section>

<style>
/* Health Articles Section */
.health-articles-section {
    padding: 48px 0;
    background: linear-gradient(180deg, #ffffff 0%, var(--surface, #f8fafc) 100%);
}

.health-articles-head h2 {
    font-family: 'Lexend', 'Sarabun', sans-serif;
}

@media (min-width: 1024px) {
    .health-articles-section {
        padding: 72px 0;
    }
}

.articles-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}

@media (min-width: 640px) {
    .articles-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 1024px) {
    .articles-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 24px;
    }
}

/* Article Card */
.article-card {
    background: white;
    border-radius: 18px;
    overflow: hidden;
    text-decoration: none;
    display: block;
    transition: transform 0.25s ease, box-shadow 0.25s ease;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.06);
    border: 1px solid rgba(15, 23, 42, 0.06);
    cursor: pointer;
}

.article-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 40px rgba(15, 23, 42, 0.12);
}

.article-image {
    aspect-ratio: 16/9;
    background: #e5e7eb;
    position: relative;
    overflow: hidden;
}

.article-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.35s ease;
}

.article-card:hover .article-image img {
    transform: scale(1.06);
}

.article-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, rgba(var(--primary-rgb, 99, 102, 241), 0.15) 0%, #e5e7eb 100%);
    color: var(--primary, #6366f1);
    font-size: 40px;
    opacity: 0.65;
}

.article-category {
    position: absolute;
    bottom: 12px;
    left: 12px;
    padding: 6px 14px;
    background: rgba(15, 23, 42, 0.82);
    backdrop-filter: blur(8px);
    color: white;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
}

.article-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    padding: 5px 12px;
    background: var(--primary, #6366f1);
    color: white;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
}

.article-content {
    padding: 18px 18px 20px;
}

.article-title {
    font-family: 'Lexend', 'Sarabun', sans-serif;
    font-size: 1.05rem;
    font-weight: 600;
    color: #0f172a;
    margin-bottom: 8px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    line-height: 1.45;
}

.article-excerpt {
    font-size: 0.875rem;
    color: #64748b;
    margin-bottom: 14px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    line-height: 1.55;
}

.article-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px 16px;
    font-size: 12px;
    color: #94a3b8;
}

.article-meta i {
    margin-right: 4px;
    color: var(--primary, #6366f1);
    opacity: 0.85;
}

.article-author {
    display: inline-flex;
    align-items: center;
}

.article-date {
    display: inline-flex;
    align-items: center;
}

.view-all-articles {
    text-align: center;
    margin-top: 36px;
}

.view-all-articles-btn {
    min-width: 220px;
    min-height: 48px;
    border-radius: 12px;
    font-weight: 600;
}
</style>
