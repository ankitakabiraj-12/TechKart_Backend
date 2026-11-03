<?php

declare(strict_types=1);

namespace App\Controllers\Buddy;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Controller handling Seller-scoped Customer Reviews, Seller Replies,
 * and Admin Content Moderation for TechKart Buddy.
 */
class BuddyReviewController
{
    private function resolveUser(Request $request): array
    {
        $user = $request->getUser();
        if (!$user) {
            Response::error('Unauthorized', 401);
        }
        if (!in_array($user['role'], ['seller', 'admin'], true)) {
            Response::error('Forbidden. Sellers and admins only.', 403);
        }
        return $user;
    }

    /**
     * Seller-scoped review list with aggregate rating statistics and filter pills.
     * GET /api/v1/buddy/seller/reviews
     * Query: rating?, has_reply?, product_id?, status?, search?, page?, limit?
     */
    public function sellerReviews(Request $request): void
    {
        $user = $this->resolveUser($request);
        $sellerId = (int)$user['id'];

        // If admin specifies seller_id, allow scoping; otherwise seller is strictly scoped to own user_id
        if ($user['role'] === 'admin') {
            $querySellerId = $request->getQuery('seller_id');
            if ($querySellerId !== null && $querySellerId !== '') {
                $sellerId = (int)$querySellerId;
            }
        }

        $ratingFilter = $request->getQuery('rating');
        $hasReplyFilter = $request->getQuery('has_reply');
        $productIdFilter = $request->getQuery('product_id');
        $statusFilter = $request->getQuery('status');
        $search = trim((string)$request->getQuery('search', ''));
        $page = max(1, (int)$request->getQuery('page', 1));
        $limit = min(50, max(1, (int)$request->getQuery('limit', 15)));
        $offset = ($page - 1) * $limit;

        try {
            $pdo = Database::getConnection();

            // 1. Calculate Seller Aggregate Ratings and Distribution (across all active/visible reviews for seller products)
            $aggSql = "SELECT
                        COUNT(pr.id) AS total_reviews,
                        COALESCE(AVG(pr.rating), 0) AS average_rating,
                        SUM(CASE WHEN pr.rating = 5 THEN 1 ELSE 0 END) AS count_5_star,
                        SUM(CASE WHEN pr.rating = 4 THEN 1 ELSE 0 END) AS count_4_star,
                        SUM(CASE WHEN pr.rating = 3 THEN 1 ELSE 0 END) AS count_3_star,
                        SUM(CASE WHEN pr.rating = 2 THEN 1 ELSE 0 END) AS count_2_star,
                        SUM(CASE WHEN pr.rating = 1 THEN 1 ELSE 0 END) AS count_1_star,
                        SUM(CASE WHEN pr.seller_reply IS NULL OR TRIM(pr.seller_reply) = '' THEN 1 ELSE 0 END) AS unreplied_count
                       FROM product_reviews pr
                       JOIN products p ON pr.product_id = p.id
                       WHERE p.seller_id = :seller_id
                         AND pr.status IN ('approved', 'flagged')";

            $aggStmt = $pdo->prepare($aggSql);
            $aggStmt->execute(['seller_id' => $sellerId]);
            $agg = $aggStmt->fetch(PDO::FETCH_ASSOC) ?: [
                'total_reviews' => 0,
                'average_rating' => 0,
                'count_5_star' => 0,
                'count_4_star' => 0,
                'count_3_star' => 0,
                'count_2_star' => 0,
                'count_1_star' => 0,
                'unreplied_count' => 0
            ];

            // 2. Build Filtered Query for Review Items
            $where = ["p.seller_id = :seller_id"];
            $params = ['seller_id' => $sellerId];

            if ($statusFilter !== null && $statusFilter !== '') {
                $where[] = "pr.status = :status";
                $params['status'] = $statusFilter;
            } else {
                // By default sellers view approved and flagged (pending/hidden reviews excluded unless requested)
                $where[] = "pr.status IN ('approved', 'flagged')";
            }

            if ($ratingFilter !== null && $ratingFilter !== '' && is_numeric($ratingFilter)) {
                $where[] = "pr.rating = :rating";
                $params['rating'] = (int)$ratingFilter;
            }

            if ($hasReplyFilter !== null && $hasReplyFilter !== '') {
                $isReply = filter_var($hasReplyFilter, FILTER_VALIDATE_BOOLEAN);
                if ($isReply) {
                    $where[] = "(pr.seller_reply IS NOT NULL AND TRIM(pr.seller_reply) != '')";
                } else {
                    $where[] = "(pr.seller_reply IS NULL OR TRIM(pr.seller_reply) = '')";
                }
            }

            if ($productIdFilter !== null && $productIdFilter !== '' && is_numeric($productIdFilter)) {
                $where[] = "pr.product_id = :product_id";
                $params['product_id'] = (int)$productIdFilter;
            }

            if ($search !== '') {
                $where[] = "(pr.title LIKE :search OR pr.review_text LIKE :search OR p.title LIKE :search)";
                $params['search'] = '%' . $search . '%';
            }

            $whereClause = implode(' AND ', $where);

            // Total count for pagination
            $countStmt = $pdo->prepare("SELECT COUNT(pr.id) FROM product_reviews pr JOIN products p ON pr.product_id = p.id WHERE {$whereClause}");
            $countStmt->execute($params);
            $totalCount = (int)$countStmt->fetchColumn();

            // Fetch records
            $sql = "SELECT
                        pr.id,
                        pr.product_id,
                        p.title AS product_title,
                        p.slug AS product_slug,
                        (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS product_image,
                        pr.customer_id,
                        COALESCE(u.full_name, 'Customer') AS customer_name,
                        u.email AS customer_email,
                        pr.rating,
                        pr.title,
                        pr.review_text,
                        pr.seller_reply,
                        pr.replied_at,
                        pr.standout_tags,
                        pr.photos,
                        pr.variant_text,
                        pr.would_recommend,
                        pr.is_verified_purchase,
                        pr.helpful_count,
                        pr.status,
                        pr.moderation_reason,
                        pr.created_at,
                        pr.updated_at
                    FROM product_reviews pr
                    JOIN products p ON pr.product_id = p.id
                    LEFT JOIN users u ON pr.customer_id = u.id
                    WHERE {$whereClause}
                    ORDER BY pr.created_at DESC
                    LIMIT {$limit} OFFSET {$offset}";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $reviews = array_map(function ($row) {
                return [
                    'id' => (int)$row['id'],
                    'product_id' => (int)$row['product_id'],
                    'product_title' => $row['product_title'],
                    'product_slug' => $row['product_slug'],
                    'product_image' => $row['product_image'],
                    'customer_id' => (int)$row['customer_id'],
                    'customer_name' => $row['customer_name'],
                    'customer_email' => $row['customer_email'],
                    'rating' => (int)$row['rating'],
                    'title' => $row['title'],
                    'review_text' => $row['review_text'],
                    'seller_reply' => $row['seller_reply'],
                    'replied_at' => $row['replied_at'],
                    'standout_tags' => !empty($row['standout_tags']) ? json_decode($row['standout_tags'], true) : [],
                    'photos' => !empty($row['photos']) ? json_decode($row['photos'], true) : [],
                    'variant_text' => $row['variant_text'],
                    'would_recommend' => (bool)$row['would_recommend'],
                    'is_verified_purchase' => (bool)$row['is_verified_purchase'],
                    'helpful_count' => (int)$row['helpful_count'],
                    'status' => $row['status'],
                    'moderation_reason' => $row['moderation_reason'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at']
                ];
            }, $rows);

            Response::json([
                'success' => true,
                'summary' => [
                    'average_rating' => round((float)$agg['average_rating'], 2),
                    'total_reviews' => (int)$agg['total_reviews'],
                    'unreplied_count' => (int)$agg['unreplied_count'],
                    'distribution' => [
                        '5_star' => (int)$agg['count_5_star'],
                        '4_star' => (int)$agg['count_4_star'],
                        '3_star' => (int)$agg['count_3_star'],
                        '2_star' => (int)$agg['count_2_star'],
                        '1_star' => (int)$agg['count_1_star'],
                    ]
                ],
                'reviews' => $reviews,
                'pagination' => [
                    'total' => $totalCount,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => (int)ceil($totalCount / $limit)
                ]
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to retrieve reviews: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Seller replies or updates their reply to a customer review on their product.
     * POST/PUT /api/v1/buddy/seller/reviews/{id}/reply
     * Body: { "seller_reply": "Thank you for your feedback! ..." }
     */
    public function replyToReview(Request $request, array $params = []): void
    {
        $user = $this->resolveUser($request);
        $reviewId = (int)($params['id'] ?? $request->get('id'));

        if ($reviewId <= 0) {
            Response::error('Review ID is required', 400);
        }

        $reply = trim((string)$request->get('seller_reply', ''));
        if ($reply === '') {
            Response::error('Reply content is required', 422);
        }

        try {
            $pdo = Database::getConnection();

            // Check review ownership via product seller_id
            $checkStmt = $pdo->prepare(
                "SELECT pr.id, pr.product_id, pr.status, p.seller_id, p.title AS product_title
                 FROM product_reviews pr
                 JOIN products p ON pr.product_id = p.id
                 WHERE pr.id = :id
                 LIMIT 1"
            );
            $checkStmt->execute(['id' => $reviewId]);
            $review = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$review) {
                Response::error('Review not found', 404);
            }

            // Enforce seller isolation: Non-admin sellers can only reply to their own products
            if ($user['role'] !== 'admin' && (int)$review['seller_id'] !== (int)$user['id']) {
                Response::error('Forbidden. You do not own the product for this review.', 403);
            }

            // Update reply
            $updateStmt = $pdo->prepare(
                "UPDATE product_reviews
                 SET seller_reply = :reply, replied_at = NOW(), updated_at = NOW()
                 WHERE id = :id"
            );
            $updateStmt->execute([
                'reply' => $reply,
                'id' => $reviewId
            ]);

            // Fetch updated review record
            $fetchStmt = $pdo->prepare(
                "SELECT pr.id, pr.product_id, pr.seller_reply, pr.replied_at, pr.updated_at
                 FROM product_reviews pr
                 WHERE pr.id = :id"
            );
            $fetchStmt->execute(['id' => $reviewId]);
            $updatedReview = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            Response::json([
                'success' => true,
                'message' => 'Seller reply submitted successfully',
                'review' => [
                    'id' => (int)$updatedReview['id'],
                    'product_id' => (int)$updatedReview['product_id'],
                    'seller_reply' => $updatedReview['seller_reply'],
                    'replied_at' => $updatedReview['replied_at']
                ]
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to submit reply: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin marketplace review listing across all sellers and statuses.
     * GET /api/v1/buddy/admin/reviews
     * Query: status?, seller_id?, rating?, search?, page?, limit?
     */
    public function adminReviews(Request $request): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Admin access required.', 403);
        }

        $statusFilter = $request->getQuery('status');
        $sellerIdFilter = $request->getQuery('seller_id');
        $ratingFilter = $request->getQuery('rating');
        $search = trim((string)$request->getQuery('search', ''));
        $page = max(1, (int)$request->getQuery('page', 1));
        $limit = min(50, max(1, (int)$request->getQuery('limit', 20)));
        $offset = ($page - 1) * $limit;

        try {
            $pdo = Database::getConnection();

            $where = ["1=1"];
            $params = [];

            if ($statusFilter !== null && $statusFilter !== '') {
                $where[] = "pr.status = :status";
                $params['status'] = $statusFilter;
            }

            if ($sellerIdFilter !== null && $sellerIdFilter !== '' && is_numeric($sellerIdFilter)) {
                $where[] = "p.seller_id = :seller_id";
                $params['seller_id'] = (int)$sellerIdFilter;
            }

            if ($ratingFilter !== null && $ratingFilter !== '' && is_numeric($ratingFilter)) {
                $where[] = "pr.rating = :rating";
                $params['rating'] = (int)$ratingFilter;
            }

            if ($search !== '') {
                $where[] = "(pr.title LIKE :search OR pr.review_text LIKE :search OR p.title LIKE :search OR u.full_name LIKE :search)";
                $params['search'] = '%' . $search . '%';
            }

            $whereClause = implode(' AND ', $where);

            $countStmt = $pdo->prepare("SELECT COUNT(pr.id) FROM product_reviews pr JOIN products p ON pr.product_id = p.id LEFT JOIN users u ON pr.customer_id = u.id WHERE {$whereClause}");
            $countStmt->execute($params);
            $totalCount = (int)$countStmt->fetchColumn();

            $sql = "SELECT
                        pr.id,
                        pr.product_id,
                        p.title AS product_title,
                        p.seller_id,
                        sp.store_name,
                        pr.customer_id,
                        COALESCE(u.full_name, 'Customer') AS customer_name,
                        u.email AS customer_email,
                        pr.rating,
                        pr.title,
                        pr.review_text,
                        pr.seller_reply,
                        pr.replied_at,
                        pr.standout_tags,
                        pr.photos,
                        pr.variant_text,
                        pr.would_recommend,
                        pr.is_verified_purchase,
                        pr.helpful_count,
                        pr.status,
                        pr.moderated_by,
                        pr.moderation_reason,
                        pr.moderated_at,
                        pr.created_at,
                        pr.updated_at
                    FROM product_reviews pr
                    JOIN products p ON pr.product_id = p.id
                    LEFT JOIN seller_profiles sp ON p.seller_id = sp.user_id
                    LEFT JOIN users u ON pr.customer_id = u.id
                    WHERE {$whereClause}
                    ORDER BY pr.created_at DESC
                    LIMIT {$limit} OFFSET {$offset}";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $reviews = array_map(function ($row) {
                return [
                    'id' => (int)$row['id'],
                    'product_id' => (int)$row['product_id'],
                    'product_title' => $row['product_title'],
                    'seller_id' => (int)$row['seller_id'],
                    'store_name' => $row['store_name'] ?? 'TechKart Seller',
                    'customer_id' => (int)$row['customer_id'],
                    'customer_name' => $row['customer_name'],
                    'customer_email' => $row['customer_email'],
                    'rating' => (int)$row['rating'],
                    'title' => $row['title'],
                    'review_text' => $row['review_text'],
                    'seller_reply' => $row['seller_reply'],
                    'replied_at' => $row['replied_at'],
                    'standout_tags' => !empty($row['standout_tags']) ? json_decode($row['standout_tags'], true) : [],
                    'photos' => !empty($row['photos']) ? json_decode($row['photos'], true) : [],
                    'variant_text' => $row['variant_text'],
                    'would_recommend' => (bool)$row['would_recommend'],
                    'is_verified_purchase' => (bool)$row['is_verified_purchase'],
                    'helpful_count' => (int)$row['helpful_count'],
                    'status' => $row['status'],
                    'moderated_by' => $row['moderated_by'] ? (int)$row['moderated_by'] : null,
                    'moderation_reason' => $row['moderation_reason'],
                    'moderated_at' => $row['moderated_at'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at']
                ];
            }, $rows);

            Response::json([
                'success' => true,
                'reviews' => $reviews,
                'pagination' => [
                    'total' => $totalCount,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => (int)ceil($totalCount / $limit)
                ]
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to fetch admin reviews: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Admin content moderation action.
     * PUT/PATCH /api/v1/buddy/admin/reviews/{id}/moderate
     * Body: { "status": "approved"|"flagged"|"hidden"|"rejected", "reason": "Optional reason" }
     */
    public function moderateReview(Request $request, array $params = []): void
    {
        $user = $this->resolveUser($request);
        if ($user['role'] !== 'admin') {
            Response::error('Forbidden. Admin access required.', 403);
        }

        $reviewId = (int)($params['id'] ?? $request->get('id'));
        if ($reviewId <= 0) {
            Response::error('Review ID is required', 400);
        }

        $status = strtolower(trim((string)$request->get('status', '')));
        $validStatuses = ['approved', 'pending', 'rejected', 'flagged', 'hidden'];
        if (!in_array($status, $validStatuses, true)) {
            Response::error('Invalid moderation status. Allowed: ' . implode(', ', $validStatuses), 422);
        }

        $reason = trim((string)$request->get('reason', ''));

        try {
            $pdo = Database::getConnection();

            $checkStmt = $pdo->prepare("SELECT id, status FROM product_reviews WHERE id = :id LIMIT 1");
            $checkStmt->execute(['id' => $reviewId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                Response::error('Review not found', 404);
            }

            $updateStmt = $pdo->prepare(
                "UPDATE product_reviews
                 SET status = :status,
                     moderated_by = :admin_id,
                     moderation_reason = :reason,
                     moderated_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $updateStmt->execute([
                'status' => $status,
                'admin_id' => (int)$user['id'],
                'reason' => $reason !== '' ? $reason : null,
                'id' => $reviewId
            ]);

            Response::json([
                'success' => true,
                'message' => "Review {$reviewId} status updated to {$status}",
                'moderation' => [
                    'id' => $reviewId,
                    'status' => $status,
                    'moderated_by' => (int)$user['id'],
                    'moderation_reason' => $reason !== '' ? $reason : null
                ]
            ]);
        } catch (Throwable $e) {
            Response::error('Failed to moderate review: ' . $e->getMessage(), 500);
        }
    }
}
