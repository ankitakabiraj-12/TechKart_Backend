<?php

declare(strict_types=1);

namespace App\Controllers\Customer;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling Product Reviews & Ratings for TechKart Customer App.
 *
 * Implements:
 * - Public paginated review listings with sorting, filters, and rating distribution summary
 * - Customer review submission with verified-purchase eligibility detection
 * - Duplicate prevention (one review per product per customer, edit/update support)
 * - Review modification (PUT/PATCH) and deletion (DELETE)
 * - Helpful vote upvoting / toggle with counter tracking
 * - Product resolution by either ID or slug (e.g. 2 or 'chronos_ultra_2')
 */
class CustomerReviewController
{
    /**
     * Resolve product integer ID from an ID or slug string.
     */
    private function resolveProductId(PDO $pdo, string|int $productIdOrSlug): ?array
    {
        $ident = trim((string)$productIdOrSlug);
        if ($ident === '') {
            return null;
        }

        $isNumeric = ctype_digit($ident);
        $sql = "SELECT id, title, slug, price FROM products
                WHERE " . ($isNumeric ? '(id = :id OR slug = :slug)' : 'slug = :slug') . "
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $binds = ['slug' => $ident];
        if ($isNumeric) {
            $binds['id'] = (int)$ident;
        }
        $stmt->execute($binds);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product && $ident === 'chronos_ultra_2') {
            // Fallback for chronos_ultra_2 to product ID 2 if not explicitly matching slug
            $stmtFallback = $pdo->prepare("SELECT id, title, slug, price FROM products WHERE id = 2 LIMIT 1");
            $stmtFallback->execute();
            $product = $stmtFallback->fetch(PDO::FETCH_ASSOC);
        }

        return $product ?: null;
    }

    /**
     * Optional customer ID resolution (returns 0 if guest).
     */
    private function getOptionalCustomerId(Request $request): int
    {
        $user = $request->getUser();
        if ($user && isset($user['id']) && $user['role'] === 'customer') {
            return (int)$user['id'];
        }
        return 0;
    }

    /**
     * Require authenticated customer ID.
     */
    private function requireCustomerId(Request $request): int
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized. Customer authentication required.', 401);
        }
        if ($user['role'] !== 'customer') {
            Response::error('Forbidden. Only customers can submit or manage reviews.', 403);
        }
        return (int)$user['id'];
    }

    /**
     * Check if a customer has a verified purchase for a product.
     */
    private function checkVerifiedPurchase(PDO $pdo, int $customerId, int $productId): bool
    {
        if ($customerId <= 0 || $productId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM order_items oi
             JOIN orders o ON oi.order_id = o.id
             WHERE o.customer_id = :customer_id
               AND oi.product_id = :product_id
               AND o.order_status IN ('processing', 'shipped', 'delivered', 'completed')"
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'product_id'  => $productId,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Build rating summary and distribution.
     */
    private function buildRatingSummary(PDO $pdo, int $productId): array
    {
        // Aggregate statistics
        $stmt = $pdo->prepare(
            "SELECT
                COUNT(*) as total_reviews,
                COALESCE(AVG(rating), 0) as average_rating,
                SUM(CASE WHEN would_recommend = 1 THEN 1 ELSE 0 END) as recommend_count,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as count_5,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as count_4,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as count_3,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as count_2,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as count_1
             FROM product_reviews
             WHERE product_id = :product_id AND status = 'approved'"
        );
        $stmt->execute(['product_id' => $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalReviews = (int)($row['total_reviews'] ?? 0);
        $avgRating = round((float)($row['average_rating'] ?? 0.0), 1);
        $recommendCount = (int)($row['recommend_count'] ?? 0);
        $recommendPercentage = $totalReviews > 0 ? (int)round(($recommendCount / $totalReviews) * 100) : 0;

        $c5 = (int)($row['count_5'] ?? 0);
        $c4 = (int)($row['count_4'] ?? 0);
        $c3 = (int)($row['count_3'] ?? 0);
        $c2 = (int)($row['count_2'] ?? 0);
        $c1 = (int)($row['count_1'] ?? 0);

        $distribution = [
            5 => [
                'stars' => 5,
                'count' => $c5,
                'percentage' => $totalReviews > 0 ? round(($c5 / $totalReviews) * 100, 1) : 0.0,
            ],
            4 => [
                'stars' => 4,
                'count' => $c4,
                'percentage' => $totalReviews > 0 ? round(($c4 / $totalReviews) * 100, 1) : 0.0,
            ],
            3 => [
                'stars' => 3,
                'count' => $c3,
                'percentage' => $totalReviews > 0 ? round(($c3 / $totalReviews) * 100, 1) : 0.0,
            ],
            2 => [
                'stars' => 2,
                'count' => $c2,
                'percentage' => $totalReviews > 0 ? round(($c2 / $totalReviews) * 100, 1) : 0.0,
            ],
            1 => [
                'stars' => 1,
                'count' => $c1,
                'percentage' => $totalReviews > 0 ? round(($c1 / $totalReviews) * 100, 1) : 0.0,
            ],
        ];

        return [
            'average_rating'       => $avgRating,
            'total_reviews'        => $totalReviews,
            'recommend_percentage' => $recommendPercentage,
            'distribution'         => $distribution,
        ];
    }

    /**
     * GET /api/v1/customer/products/{id}/reviews
     * Retrieve reviews for a product with pagination, sorting, filters, and aggregate summary.
     */
    public function index(Request $request, array $params): void
    {
        $productIdParam = $params['id'] ?? '';
        $customerId = $this->getOptionalCustomerId($request);

        try {
            $pdo = Database::getConnection();
            $product = $this->resolveProductId($pdo, $productIdParam);

            if (!$product) {
                Response::error('Product not found.', 404);
            }
            $productId = (int)$product['id'];

            // Query parameters
            $page           = max(1, (int)$request->getQuery('page', 1));
            $limit          = min(50, max(1, (int)$request->getQuery('limit', 10)));
            $offset         = ($page - 1) * $limit;
            $sortBy         = (string)$request->getQuery('sort_by', 'most_relevant');
            $verifiedOnly   = filter_var($request->getQuery('verified_only', false), FILTER_VALIDATE_BOOLEAN);
            $withPhotosOnly = filter_var($request->getQuery('with_photos', false), FILTER_VALIDATE_BOOLEAN);

            // Base WHERE clause
            $whereConditions = ["r.product_id = :product_id", "r.status = 'approved'"];
            $binds = ['product_id' => $productId];

            if ($verifiedOnly) {
                $whereConditions[] = "r.is_verified_purchase = 1";
            }
            if ($withPhotosOnly) {
                $whereConditions[] = "r.photos IS NOT NULL AND JSON_LENGTH(r.photos) > 0";
            }

            $whereSql = implode(' AND ', $whereConditions);

            // Sorting order
            $orderSql = match ($sortBy) {
                'latest'       => "r.created_at DESC",
                'oldest'       => "r.created_at ASC",
                'rating_high'  => "r.rating DESC, r.created_at DESC",
                'rating_low'   => "r.rating ASC, r.created_at DESC",
                'helpful'      => "r.helpful_count DESC, r.created_at DESC",
                default        => "r.is_verified_purchase DESC, r.helpful_count DESC, r.created_at DESC", // most_relevant
            };

            // Count total matching reviews
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM product_reviews r WHERE {$whereSql}");
            $countStmt->execute($binds);
            $totalFiltered = (int)$countStmt->fetchColumn();

            // Fetch reviews
            $selectSql = "SELECT
                            r.id,
                            r.product_id,
                            r.customer_id,
                            u.full_name AS customer_name,
                            u.avatar_url AS customer_avatar,
                            r.rating,
                            r.title,
                            r.review_text,
                            r.standout_tags,
                            r.photos,
                            r.variant_text,
                            r.would_recommend,
                            r.is_verified_purchase,
                            r.helpful_count,
                            r.created_at,
                            r.updated_at
                          FROM product_reviews r
                          JOIN users u ON r.customer_id = u.id
                          WHERE {$whereSql}
                          ORDER BY {$orderSql}
                          LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($selectSql);
            foreach ($binds as $key => $val) {
                $stmt->bindValue(':' . $key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $reviewRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch helpful votes voted by current customer
            $userVotedIds = [];
            if ($customerId > 0 && !empty($reviewRows)) {
                $revIds = array_column($reviewRows, 'id');
                $inPlaceholders = implode(',', array_fill(0, count($revIds), '?'));
                $voteStmt = $pdo->prepare(
                    "SELECT review_id FROM review_helpful_votes
                     WHERE customer_id = ? AND review_id IN ({$inPlaceholders})"
                );
                $voteStmt->execute(array_merge([$customerId], $revIds));
                $userVotedIds = array_flip($voteStmt->fetchAll(PDO::FETCH_COLUMN));
            }

            // Customer's existing review on this product (if logged in)
            $userReview = null;
            $canReview = true;
            $isVerifiedBuyer = false;

            if ($customerId > 0) {
                $isVerifiedBuyer = $this->checkVerifiedPurchase($pdo, $customerId, $productId);

                $userRevStmt = $pdo->prepare(
                    "SELECT id, rating, title, review_text, standout_tags, photos, variant_text,
                            would_recommend, is_verified_purchase, helpful_count, created_at
                     FROM product_reviews
                     WHERE product_id = :product_id AND customer_id = :customer_id
                     LIMIT 1"
                );
                $userRevStmt->execute([
                    'product_id'  => $productId,
                    'customer_id' => $customerId,
                ]);
                $existingReview = $userRevStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingReview) {
                    $existingReview['standout_tags'] = !empty($existingReview['standout_tags'])
                        ? json_decode($existingReview['standout_tags'], true) : [];
                    $existingReview['photos'] = !empty($existingReview['photos'])
                        ? json_decode($existingReview['photos'], true) : [];
                    $existingReview['would_recommend'] = (bool)$existingReview['would_recommend'];
                    $existingReview['is_verified_purchase'] = (bool)$existingReview['is_verified_purchase'];
                    $userReview = $existingReview;
                }
            }

            // Format items
            $formattedReviews = [];
            foreach ($reviewRows as $rev) {
                $tags = !empty($rev['standout_tags']) ? json_decode($rev['standout_tags'], true) : [];
                $photos = !empty($rev['photos']) ? json_decode($rev['photos'], true) : [];
                $nameParts = explode(' ', trim($rev['customer_name']));
                $initials = '';
                if (count($nameParts) >= 2) {
                    $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts) - 1], 0, 1));
                } else {
                    $initials = strtoupper(substr($rev['customer_name'], 0, min(2, strlen($rev['customer_name']))));
                }

                $formattedReviews[] = [
                    'id'                   => (int)$rev['id'],
                    'product_id'           => (int)$rev['product_id'],
                    'customer_id'          => (int)$rev['customer_id'],
                    'customer_name'        => $rev['customer_name'],
                    'initials'             => $initials,
                    'customer_avatar'      => $rev['customer_avatar'],
                    'rating'               => (int)$rev['rating'],
                    'title'                => $rev['title'],
                    'review_text'          => $rev['review_text'],
                    'standout_tags'        => is_array($tags) ? $tags : [],
                    'photos'               => is_array($photos) ? $photos : [],
                    'variant_text'         => $rev['variant_text'] ?: 'Standard Edition',
                    'would_recommend'      => (bool)$rev['would_recommend'],
                    'is_verified_purchase' => (bool)$rev['is_verified_purchase'],
                    'helpful_count'        => (int)$rev['helpful_count'],
                    'has_voted_helpful'    => isset($userVotedIds[$rev['id']]),
                    'created_at'           => $rev['created_at'],
                ];
            }

            $summary = $this->buildRatingSummary($pdo, $productId);

            Response::json([
                'product' => [
                    'id'    => $productId,
                    'title' => $product['title'],
                    'slug'  => $product['slug'],
                    'price' => (float)$product['price'],
                ],
                'summary' => $summary,
                'eligibility' => [
                    'is_authenticated'    => $customerId > 0,
                    'is_verified_buyer'   => $isVerifiedBuyer,
                    'has_reviewed'        => $userReview !== null,
                    'user_review'         => $userReview,
                ],
                'reviews' => $formattedReviews,
            ], 200, 'Reviews retrieved successfully', [
                'pagination' => [
                    'total'        => $totalFiltered,
                    'page'         => $page,
                    'limit'        => $limit,
                    'total_pages'  => ceil($totalFiltered / $limit) ?: 1,
                    'has_next'     => ($offset + $limit) < $totalFiltered,
                    'has_previous' => $page > 1,
                ]
            ]);

        } catch (Throwable $e) {
            Response::error('Failed to retrieve reviews: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/customer/products/{id}/reviews
     * Submit new product review. Supports upsert or conflict detection.
     */
    public function store(Request $request, array $params): void
    {
        $customerId = $this->requireCustomerId($request);
        $productIdParam = $params['id'] ?? '';

        try {
            $pdo = Database::getConnection();
            $product = $this->resolveProductId($pdo, $productIdParam);
            if (!$product) {
                Response::error('Product not found.', 404);
            }
            $productId = (int)$product['id'];

            // Validation
            $rating = (int)$request->get('rating', 0);
            $title = trim((string)$request->get('title', ''));
            $reviewText = trim((string)$request->get('review_text', ''));
            $standoutTags = $request->get('standout_tags', []);
            $photos = $request->get('photos', []);
            $variantText = trim((string)$request->get('variant_text', ''));
            $wouldRecommend = filter_var($request->get('would_recommend', true), FILTER_VALIDATE_BOOLEAN);

            $errors = [];
            if ($rating < 1 || $rating > 5) {
                $errors['rating'] = 'Rating must be an integer between 1 and 5 stars.';
            }
            if (empty($title)) {
                $errors['title'] = 'Review title is required.';
            } elseif (mb_strlen($title) > 255) {
                $errors['title'] = 'Review title cannot exceed 255 characters.';
            }
            if (empty($reviewText)) {
                $errors['review_text'] = 'Review text is required.';
            } elseif (mb_strlen($reviewText) < 20) {
                $errors['review_text'] = 'Review text must be at least 20 characters.';
            }

            if (!empty($errors)) {
                Response::error('Validation failed.', 422, $errors);
            }

            if (!is_array($standoutTags)) {
                $standoutTags = [];
            }
            if (!is_array($photos)) {
                $photos = [];
            }

            // Verify if customer has purchased this product
            $isVerified = $this->checkVerifiedPurchase($pdo, $customerId, $productId);

            // Duplicate prevention: check existing review
            $checkStmt = $pdo->prepare("SELECT id FROM product_reviews WHERE product_id = :pid AND customer_id = :cid");
            $checkStmt->execute(['pid' => $productId, 'cid' => $customerId]);
            $existingId = $checkStmt->fetchColumn();

            if ($existingId) {
                // Update existing review
                $updStmt = $pdo->prepare(
                    "UPDATE product_reviews
                     SET rating = :rating,
                         title = :title,
                         review_text = :review_text,
                         standout_tags = :standout_tags,
                         photos = :photos,
                         variant_text = :variant_text,
                         would_recommend = :would_recommend,
                         is_verified_purchase = :is_verified_purchase,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $updStmt->execute([
                    'rating'               => $rating,
                    'title'                => $title,
                    'review_text'          => $reviewText,
                    'standout_tags'        => json_encode($standoutTags),
                    'photos'               => json_encode($photos),
                    'variant_text'         => $variantText ?: 'Grade 5 Titanium • 49mm Cellular',
                    'would_recommend'      => $wouldRecommend ? 1 : 0,
                    'is_verified_purchase' => $isVerified ? 1 : 0,
                    'id'                   => $existingId,
                ]);

                $reviewId = (int)$existingId;
                $message = 'Review updated successfully!';
            } else {
                // Insert new review
                $insStmt = $pdo->prepare(
                    "INSERT INTO product_reviews
                     (product_id, customer_id, rating, title, review_text, standout_tags, photos, variant_text, would_recommend, is_verified_purchase, helpful_count, status, created_at, updated_at)
                     VALUES (:product_id, :customer_id, :rating, :title, :review_text, :standout_tags, :photos, :variant_text, :would_recommend, :is_verified_purchase, 0, 'approved', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
                );
                $insStmt->execute([
                    'product_id'           => $productId,
                    'customer_id'          => $customerId,
                    'rating'               => $rating,
                    'title'                => $title,
                    'review_text'          => $reviewText,
                    'standout_tags'        => json_encode($standoutTags),
                    'photos'               => json_encode($photos),
                    'variant_text'         => $variantText ?: 'Grade 5 Titanium • 49mm Cellular',
                    'would_recommend'      => $wouldRecommend ? 1 : 0,
                    'is_verified_purchase' => $isVerified ? 1 : 0,
                ]);

                $reviewId = (int)$pdo->lastInsertId();
                $message = 'Review submitted successfully!';
            }

            $summary = $this->buildRatingSummary($pdo, $productId);

            Response::json([
                'review_id'            => $reviewId,
                'product_id'           => $productId,
                'rating'               => $rating,
                'title'                => $title,
                'is_verified_purchase' => $isVerified,
                'summary'              => $summary,
            ], 201, $message);

        } catch (Throwable $e) {
            Response::error('Failed to submit review: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PUT/PATCH /api/v1/customer/reviews/{id}
     * Update an existing review belonging to the authenticated customer.
     */
    public function update(Request $request, array $params): void
    {
        $customerId = $this->requireCustomerId($request);
        $reviewId = (int)($params['id'] ?? 0);

        if ($reviewId <= 0) {
            Response::error('Invalid review ID.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $checkStmt = $pdo->prepare("SELECT id, product_id, customer_id FROM product_reviews WHERE id = :id");
            $checkStmt->execute(['id' => $reviewId]);
            $review = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$review) {
                Response::error('Review not found.', 404);
            }
            if ((int)$review['customer_id'] !== $customerId) {
                Response::error('Forbidden. You can only edit your own reviews.', 403);
            }

            $rating = (int)$request->get('rating', 0);
            $title = trim((string)$request->get('title', ''));
            $reviewText = trim((string)$request->get('review_text', ''));
            $standoutTags = $request->get('standout_tags', []);
            $photos = $request->get('photos', []);
            $wouldRecommend = filter_var($request->get('would_recommend', true), FILTER_VALIDATE_BOOLEAN);

            $errors = [];
            if ($rating < 1 || $rating > 5) {
                $errors['rating'] = 'Rating must be an integer between 1 and 5.';
            }
            if (empty($title)) {
                $errors['title'] = 'Review title is required.';
            }
            if (empty($reviewText) || mb_strlen($reviewText) < 20) {
                $errors['review_text'] = 'Review text must be at least 20 characters.';
            }
            if (!empty($errors)) {
                Response::error('Validation failed.', 422, $errors);
            }

            $updStmt = $pdo->prepare(
                "UPDATE product_reviews
                 SET rating = :rating,
                     title = :title,
                     review_text = :review_text,
                     standout_tags = :standout_tags,
                     photos = :photos,
                     would_recommend = :would_recommend,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            );
            $updStmt->execute([
                'rating'          => $rating,
                'title'           => $title,
                'review_text'     => $reviewText,
                'standout_tags'   => json_encode(is_array($standoutTags) ? $standoutTags : []),
                'photos'          => json_encode(is_array($photos) ? $photos : []),
                'would_recommend' => $wouldRecommend ? 1 : 0,
                'id'              => $reviewId,
            ]);

            $summary = $this->buildRatingSummary($pdo, (int)$review['product_id']);

            Response::json([
                'review_id'  => $reviewId,
                'product_id' => (int)$review['product_id'],
                'summary'    => $summary,
            ], 200, 'Review updated successfully!');

        } catch (Throwable $e) {
            Response::error('Failed to update review: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v1/customer/reviews/{id}
     * Delete a review belonging to the authenticated customer.
     */
    public function destroy(Request $request, array $params): void
    {
        $customerId = $this->requireCustomerId($request);
        $reviewId = (int)($params['id'] ?? 0);

        if ($reviewId <= 0) {
            Response::error('Invalid review ID.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $checkStmt = $pdo->prepare("SELECT id, product_id, customer_id FROM product_reviews WHERE id = :id");
            $checkStmt->execute(['id' => $reviewId]);
            $review = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$review) {
                Response::error('Review not found.', 404);
            }
            if ((int)$review['customer_id'] !== $customerId) {
                Response::error('Forbidden. You can only delete your own reviews.', 403);
            }

            $delStmt = $pdo->prepare("DELETE FROM product_reviews WHERE id = :id");
            $delStmt->execute(['id' => $reviewId]);

            $summary = $this->buildRatingSummary($pdo, (int)$review['product_id']);

            Response::json([
                'deleted_review_id' => $reviewId,
                'product_id'        => (int)$review['product_id'],
                'summary'           => $summary,
            ], 200, 'Review deleted successfully');

        } catch (Throwable $e) {
            Response::error('Failed to delete review: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v1/customer/reviews/{id}/helpful
     * Toggle helpful vote for a review.
     */
    public function voteHelpful(Request $request, array $params): void
    {
        $customerId = $this->requireCustomerId($request);
        $reviewId = (int)($params['id'] ?? 0);

        if ($reviewId <= 0) {
            Response::error('Invalid review ID.', 400);
        }

        try {
            $pdo = Database::getConnection();

            $revStmt = $pdo->prepare("SELECT id, helpful_count FROM product_reviews WHERE id = :id");
            $revStmt->execute(['id' => $reviewId]);
            $review = $revStmt->fetch(PDO::FETCH_ASSOC);

            if (!$review) {
                Response::error('Review not found.', 404);
            }

            // Check if user already voted
            $voteStmt = $pdo->prepare("SELECT id FROM review_helpful_votes WHERE review_id = :rid AND customer_id = :cid");
            $voteStmt->execute(['rid' => $reviewId, 'cid' => $customerId]);
            $voteId = $voteStmt->fetchColumn();

            if ($voteId) {
                // Remove vote
                $delVote = $pdo->prepare("DELETE FROM review_helpful_votes WHERE id = :id");
                $delVote->execute(['id' => $voteId]);

                $updRev = $pdo->prepare("UPDATE product_reviews SET helpful_count = GREATEST(0, helpful_count - 1) WHERE id = :id");
                $updRev->execute(['id' => $reviewId]);
                $hasVoted = false;
            } else {
                // Add vote
                $insVote = $pdo->prepare("INSERT INTO review_helpful_votes (review_id, customer_id, created_at) VALUES (:rid, :cid, CURRENT_TIMESTAMP)");
                $insVote->execute(['rid' => $reviewId, 'cid' => $customerId]);

                $updRev = $pdo->prepare("UPDATE product_reviews SET helpful_count = helpful_count + 1 WHERE id = :id");
                $updRev->execute(['id' => $reviewId]);
                $hasVoted = true;
            }

            // Fetch new helpful count
            $cntStmt = $pdo->prepare("SELECT helpful_count FROM product_reviews WHERE id = :id");
            $cntStmt->execute(['id' => $reviewId]);
            $newCount = (int)$cntStmt->fetchColumn();

            Response::json([
                'review_id'     => $reviewId,
                'has_voted'     => $hasVoted,
                'helpful_count' => $newCount,
            ], 200, $hasVoted ? 'Helpful vote recorded' : 'Helpful vote removed');

        } catch (Throwable $e) {
            Response::error('Failed to register helpful vote: ' . $e->getMessage(), 500);
        }
    }
}
