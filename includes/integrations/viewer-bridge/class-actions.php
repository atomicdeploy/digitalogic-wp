<?php

declare(strict_types=1);

namespace Digitalogic\ViewerBridge;

use WC_Order;
use WC_Product;
use WP_Error;
use WP_REST_Request;
use WP_Term;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Narrow, audited mutation dispatcher. There is intentionally no generic
 * WordPress/Woo command execution path.
 */
final class Actions
{
    private const ACTIONS = array(
        'category.rename',
        'category.move',
        'category.map_source',
        'category.unmap_source',
        'product.rename',
        'product.set_status',
        'product.set_stock_status',
        'product.assign_categories',
        'order.set_status',
        'customer.set_segment',
        'entity.trash',
        'entity.restore',
    );

    public static function register_product_trash_status(): void
    {
        register_post_status(
            Live_State::PRODUCT_TRASH_STATUS,
            array(
                'label' => __('Viewer Trash', 'digitalogic-viewer-bridge'),
                'public' => false,
                'internal' => true,
                'protected' => false,
                'private' => false,
                'exclude_from_search' => true,
                'show_in_admin_all_list' => true,
                'show_in_admin_status_list' => true,
                'label_count' => _n_noop(
                    'Viewer Trash <span class="count">(%s)</span>',
                    'Viewer Trash <span class="count">(%s)</span>',
                    'digitalogic-viewer-bridge'
                ),
            )
        );
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    public static function handle(WP_REST_Request $request)
    {
        $body = $request->get_json_params();
        if (
            !is_array($body)
            || !self::exact_keys(
                $body,
                array('action', 'entityType', 'entityId', 'revision', 'payload'),
                array('action', 'entityType', 'entityId', 'revision', 'payload')
            )
            || !is_array($body['payload'])
        ) {
            return self::error(
                'request_shape_invalid',
                'The action request must match the exact contract shape.',
                400
            );
        }
        $action = trim((string) $body['action']);
        $entity_type = trim((string) $body['entityType']);
        $entity_id = trim((string) $body['entityId']);
        $payload = $body['payload'];
        if (
            !in_array($action, self::ACTIONS, true)
            || (
                !str_starts_with($action, $entity_type . '.')
                && !in_array($action, array('entity.trash', 'entity.restore'), true)
            )
            || (
                in_array($action, array('entity.trash', 'entity.restore'), true)
                && !in_array($entity_type, array('category', 'product'), true)
            )
        ) {
            return self::error('action_not_allowed', 'The requested action is not allowed.', 403);
        }
        if (!preg_match('/^[a-z][a-z0-9_.:-]{1,127}$/i', $entity_id)) {
            return self::error('entity_id_invalid', 'The entity identifier is invalid.', 400);
        }

        $expected = self::expected_revision($request, $body);
        if ($expected instanceof WP_Error) {
            return $expected;
        }
        $request_key = trim((string) $request->get_header('Idempotency-Key'));
        $loaded = self::load_entity($entity_type, $entity_id);
        if ($loaded instanceof WP_Error) {
            return $loaded;
        }
        $safe_payload = self::safe_payload($action, $entity_type, $payload);
        if ($safe_payload instanceof WP_Error) {
            return $safe_payload;
        }

        $started = Store::begin_action(
            $request_key,
            $action,
            $entity_type,
            $entity_id,
            $expected,
            $safe_payload
        );
        if ($started instanceof WP_Error) {
            return $started;
        }
        if ($started['state'] === 'replay') {
            $response = (array) ($started['response'] ?? array());
            if (($response['eventDelivery'] ?? '') === 'pending') {
                $event = self::emit_action_event(
                    $action,
                    $entity_type,
                    $safe_payload,
                    $response
                );
                if ($event instanceof WP_Error) {
                    return self::error(
                        'event_delivery_pending',
                        'The mutation is committed, but its live event still requires retry.',
                        503,
                        array(
                            'mutationCommitted' => true,
                            'revision' => $response['revision'] ?? '',
                        )
                    );
                }
                $response['eventDelivery'] = 'delivered';
                if (
                    !Store::complete_action(
                        absint($started['row']['id'] ?? 0),
                        (string) ($response['revision'] ?? ''),
                        $response
                    )
                ) {
                    return self::error(
                        'audit_completion_failed',
                        'The event was delivered, but its audit checkpoint requires retry.',
                        503,
                        array('mutationCommitted' => true)
                    );
                }
            }
            $response['idempotentReplay'] = true;
            return $response;
        }
        $audit_id = absint($started['row']['id'] ?? 0);

        if (!hash_equals((string) $loaded['revision'], $expected)) {
            $error = self::error(
                'revision_conflict',
                'The entity changed after it was loaded. Refresh and try again.',
                412,
                array('currentRevision' => $loaded['revision'])
            );
            Store::fail_action($audit_id, $error);
            return $error;
        }

        $result = self::mutate($action, $entity_id, $loaded, $safe_payload);
        if ($result instanceof WP_Error) {
            Store::fail_action($audit_id, $result);
            return $result;
        }

        $response = array(
            'ok' => true,
            'action' => $action,
            'entityType' => $entity_type,
            'entityId' => (string) ($result['entityId'] ?? $entity_id),
            'previousRevision' => (string) $loaded['revision'],
            'revision' => (string) $result['revision'],
            'occurredAt' => gmdate('c'),
            'idempotentReplay' => false,
        );
        if (isset($result['eventType'])) {
            $response['eventType'] = (string) $result['eventType'];
        }
        $response['eventDelivery'] = 'pending';
        if (!Store::complete_action($audit_id, $response['revision'], $response)) {
            return self::error(
                'audit_completion_failed',
                'The mutation succeeded, but its audit record could not be finalized.',
                503
            );
        }

        $event = self::emit_action_event(
            $action,
            $entity_type,
            $safe_payload,
            $response
        );
        if ($event instanceof WP_Error) {
            return self::error(
                'event_delivery_pending',
                'The mutation is committed, but its live event requires retry.',
                503,
                array(
                    'mutationCommitted' => true,
                    'revision' => $response['revision'],
                    'idempotencyRetryRequired' => true,
                )
            );
        }
        $response['eventDelivery'] = 'delivered';
        if (
            !Store::complete_action(
                $audit_id,
                $response['revision'],
                $response
            )
        ) {
            return self::error(
                'audit_completion_failed',
                'The event was delivered, but its audit checkpoint requires retry.',
                503,
                array('mutationCommitted' => true)
            );
        }
        return $response;
    }

    /**
     * @param array<string,mixed> $safe_payload
     * @param array<string,mixed> $response
     * @return array<string,mixed>|WP_Error|null
     */
    private static function emit_action_event(
        string $action,
        string $entity_type,
        array $safe_payload,
        array $response
    ) {
        $event_changes = array_keys($safe_payload);
        if (in_array($action, array('entity.trash', 'entity.restore'), true)) {
            $event_changes[] = 'lifecycle';
        }
        $entity_id = (string) ($response['entityId'] ?? '');
        $revision = (string) ($response['revision'] ?? '');
        $event_value = Live_State::event_value_by_id(
            $entity_type,
            $entity_id
        );
        if (!is_array($event_value)) {
            $event_value = array(
                'id' => $entity_id,
                'revision' => $revision,
            );
            if ($action === 'entity.trash') {
                $event_value['lifecycleStatus'] = 'trashed';
            } elseif ($action === 'entity.restore') {
                $event_value['lifecycleStatus'] = 'active';
            }
        }
        return Events::emit(
            (string) ($response['eventType'] ?? $action),
            $entity_type,
            $entity_id,
            $revision,
            $event_changes,
            $event_value
        );
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private static function load_entity(string $entity_type, string $entity_id)
    {
        if ($entity_type === 'category') {
            $context = Live_State::canonical_context_for_actions();
            $resolved = self::resolve_category($entity_id, $context);
            if ($resolved instanceof WP_Error) {
                return $resolved;
            }
            $term = get_term($resolved['termId'], 'product_cat');
            if (!$term instanceof WP_Term) {
                return self::error('category_not_found', 'Category not found.', 404);
            }
            return array(
                'object' => $term,
                'termId' => $term->term_id,
                'context' => $context,
                'node' => $resolved['node'],
                'matchState' => $resolved['matchState'],
                'lifecycleStatus' => (string) (
                    $resolved['node']['lifecycleStatus'] ?? 'active'
                ),
                'revision' => (string) ($resolved['node']['revision'] ?? Revision::category($term)),
            );
        }
        if ($entity_type === 'product') {
            $id = Live_State::woo_id($entity_id, 'product');
            $object = $id > 0 ? wc_get_product($id) : null;
            if (!$object instanceof WC_Product) {
                return self::error(
                    'product_not_mutable',
                    'Only an existing WooCommerce product can be changed.',
                    404
                );
            }
            return array('object' => $object, 'revision' => Revision::product($object));
        }
        if ($entity_type === 'order') {
            $id = Live_State::woo_id($entity_id, 'order');
            $object = $id > 0 ? wc_get_order($id) : null;
            if (!$object instanceof WC_Order) {
                return self::error('order_not_found', 'Order not found.', 404);
            }
            return array('object' => $object, 'revision' => Revision::order($object));
        }
        if ($entity_type === 'customer') {
            $id = Live_State::woo_id($entity_id, 'customer');
            if ($id < 1 || !get_userdata($id)) {
                return self::error('customer_not_found', 'Customer not found.', 404);
            }
            return array('userId' => $id, 'revision' => Revision::customer($id));
        }
        return self::error('entity_type_not_allowed', 'Entity type is not allowed.', 403);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private static function mutate(
        string $action,
        string $entity_id,
        array $loaded,
        array $payload
    ) {
        if (in_array($action, array('entity.trash', 'entity.restore'), true)) {
            return self::mutate_lifecycle($action, $entity_id, $loaded, $payload);
        }
        if (str_starts_with($action, 'category.')) {
            return self::mutate_category($action, $entity_id, $loaded, $payload);
        }
        if (str_starts_with($action, 'product.')) {
            return self::mutate_product($action, $loaded, $payload);
        }
        if ($action === 'order.set_status') {
            /** @var WC_Order $order */
            $order = $loaded['object'];
            $from = $order->get_status();
            $to = (string) $payload['status'];
            if (!in_array($to, Live_State::allowed_order_transitions($from), true)) {
                return self::error(
                    'order_transition_not_allowed',
                    'Only hold, resume, processing, and completion transitions are allowed.',
                    409
                );
            }
            $order->set_status($to);
            $order->save();
            return array('revision' => Revision::order($order));
        }
        if ($action === 'customer.set_segment') {
            $user_id = (int) $loaded['userId'];
            update_user_meta(
                $user_id,
                '_digitalogic_viewer_segment',
                (string) $payload['segment']
            );
            return array('revision' => Revision::customer($user_id));
        }
        return self::error('action_not_implemented', 'Action is not implemented.', 501);
    }

    /**
     * Guaranteed soft lifecycle changes. This method never invokes
     * wp_delete_post(), wp_delete_term(), or Woo deletion APIs.
     *
     * @return array<string,mixed>|WP_Error
     */
    private static function mutate_lifecycle(
        string $action,
        string $entity_id,
        array $loaded,
        array $payload
    ) {
        if ($loaded['object'] instanceof WC_Product) {
            /** @var WC_Product $product */
            $product = $loaded['object'];
            $product_id = $product->get_id();
            if (get_post_type($product_id) !== 'product') {
                return self::error(
                    'lifecycle_product_type_not_allowed',
                    'Only top-level WooCommerce products support lifecycle actions.',
                    409
                );
            }
            $is_trashed =
                get_post_status($product_id) === Live_State::PRODUCT_TRASH_STATUS;
            if ($action === 'entity.trash') {
                if ($is_trashed) {
                    return self::error('already_trashed', 'Product is already trashed.', 409);
                }
                $previous = get_post_status($product_id);
                if (!in_array($previous, array('publish', 'draft', 'pending', 'private'), true)) {
                    $previous = 'draft';
                }
                update_post_meta(
                    $product_id,
                    '_digitalogic_viewer_previous_status',
                    $previous
                );
                $updated = wp_update_post(
                    array(
                        'ID' => $product_id,
                        'post_status' => Live_State::PRODUCT_TRASH_STATUS,
                    ),
                    true
                );
                if ($updated instanceof WP_Error) {
                    return $updated;
                }
                clean_post_cache($product_id);
                $product = wc_get_product($product_id);
                if (!$product instanceof WC_Product) {
                    return self::error(
                        'product_reload_failed',
                        'The softly trashed product could not be reloaded.',
                        503
                    );
                }
                return array(
                    'eventType' => 'product.deleted',
                    'revision' => Revision::product($product),
                );
            }
            if (!$is_trashed) {
                return self::error('not_trashed', 'Product is not trashed.', 409);
            }
            $restore_status = sanitize_key(
                (string) get_post_meta(
                    $product_id,
                    '_digitalogic_viewer_previous_status',
                    true
                )
            );
            if (!in_array($restore_status, array('publish', 'draft', 'pending', 'private'), true)) {
                $restore_status = 'draft';
            }
            $updated = wp_update_post(
                array('ID' => $product_id, 'post_status' => $restore_status),
                true
            );
            if ($updated instanceof WP_Error) {
                return $updated;
            }
            delete_post_meta($product_id, '_digitalogic_viewer_previous_status');
            clean_post_cache($product_id);
            $product = wc_get_product($product_id);
            if (!$product instanceof WC_Product) {
                return self::error(
                    'product_reload_failed',
                    'The restored product could not be reloaded.',
                    503
                );
            }
            return array(
                'eventType' => 'product.restored',
                'revision' => Revision::product($product),
            );
        }

        if (!$loaded['object'] instanceof WP_Term) {
            return self::error('lifecycle_entity_not_allowed', 'Entity is not mutable.', 409);
        }
        if (in_array((string) $loaded['matchState'], array('suggested', 'unresolved'), true)) {
            return self::error(
                'category_mapping_requires_review',
                'Suggested or unresolved categories are read-only until explicitly mapped.',
                409
            );
        }

        $term_id = (int) $loaded['termId'];
        $trashed_at = (string) get_term_meta(
            $term_id,
            '_digitalogic_viewer_trashed_at',
            true
        );
        if ($action === 'entity.restore') {
            if ($trashed_at === '') {
                return self::error('not_trashed', 'Category is not trashed.', 409);
            }
            delete_term_meta($term_id, '_digitalogic_viewer_trashed_at');
            delete_term_meta($term_id, '_digitalogic_viewer_reassigned_to');
            $context = Live_State::canonical_context_for_actions();
            $canonical_id = (string) (
                $context['canonicalByTerm'][$term_id]
                ?? Live_State::category_id($term_id)
            );
            return array(
                'entityId' => $canonical_id,
                'eventType' => 'category.restored',
                'revision' => (string) (
                    $context['nodes'][$canonical_id]['revision']
                    ?? Revision::category(get_term($term_id, 'product_cat'))
                ),
            );
        }
        if ($trashed_at !== '') {
            return self::error('already_trashed', 'Category is already trashed.', 409);
        }

        $children = get_terms(
            array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'parent' => $term_id,
                'fields' => 'ids',
                'number' => 1001,
            )
        );
        if (is_wp_error($children)) {
            return $children;
        }
        $children = array_values(array_map('intval', (array) $children));
        $objects = get_objects_in_term($term_id, 'product_cat');
        if (is_wp_error($objects)) {
            return $objects;
        }
        $product_ids = array_values(
            array_filter(
                array_map('absint', (array) $objects),
                static fn(int $id): bool => get_post_type($id) === 'product'
            )
        );
        if (count($children) > 1000 || count($product_ids) > 2000) {
            return self::error(
                'category_reassignment_too_large',
                'Use the dedicated WordPress bulk tools for this category.',
                409
            );
        }

        $target_id = 0;
        $target_canonical_id = (string) ($payload['reassignToCategoryId'] ?? '');
        $source_managed = false;
        foreach ((array) ($loaded['node']['sourceRefs'] ?? array()) as $source_ref) {
            if (
                is_array($source_ref)
                && ($source_ref['source'] ?? '') === 'patris.product-sync'
            ) {
                $source_managed = true;
                break;
            }
        }
        if (($children || $product_ids || $source_managed) && $target_canonical_id === '') {
            return self::error(
                $source_managed
                    ? 'category_source_managed'
                    : 'category_not_empty',
                $source_managed
                    ? 'A Patris-managed category requires an explicit reassignment target.'
                    : 'This category has children or products; choose an explicit reassignment target.',
                409,
                array(
                    'childCount' => count($children),
                    'productCount' => count($product_ids),
                    'sourceManaged' => $source_managed,
                )
            );
        }
        if ($children || $product_ids || $source_managed) {
            $target = self::resolve_category($target_canonical_id, $loaded['context']);
            if ($target instanceof WP_Error) {
                return $target;
            }
            if (
                (int) $target['termId'] === $term_id
                || term_is_ancestor_of(
                    $term_id,
                    (int) $target['termId'],
                    'product_cat'
                )
                || in_array($target['matchState'], array('suggested', 'unresolved'), true)
                || ($target['node']['lifecycleStatus'] ?? 'active') === 'trash'
            ) {
                return self::error(
                    'category_reassignment_target_invalid',
                    'The reassignment target must be a different active reviewed category.',
                    409
                );
            }
            $target_id = (int) $target['termId'];
        } elseif ($target_canonical_id !== '') {
            $target = self::resolve_category($target_canonical_id, $loaded['context']);
            if ($target instanceof WP_Error) {
                return $target;
            }
            if (
                (int) $target['termId'] === $term_id
                || term_is_ancestor_of(
                    $term_id,
                    (int) $target['termId'],
                    'product_cat'
                )
                || in_array($target['matchState'], array('suggested', 'unresolved'), true)
                || ($target['node']['lifecycleStatus'] ?? 'active') === 'trash'
            ) {
                return self::error(
                    'category_reassignment_target_invalid',
                    'The reassignment target must be a different active reviewed category.',
                    409
                );
            }
            $target_id = (int) $target['termId'];
        }

        foreach ($children as $child_id) {
            $updated = wp_update_term(
                $child_id,
                'product_cat',
                array('parent' => $target_id)
            );
            if (is_wp_error($updated)) {
                return $updated;
            }
        }
        foreach ($product_ids as $product_id) {
            if ($target_id > 0) {
                $assigned = wp_set_object_terms(
                    $product_id,
                    array($target_id),
                    'product_cat',
                    true
                );
                if (is_wp_error($assigned)) {
                    return $assigned;
                }
            }
            $removed = wp_remove_object_terms($product_id, array($term_id), 'product_cat');
            if (is_wp_error($removed)) {
                return $removed;
            }
        }
        update_term_meta($term_id, '_digitalogic_viewer_trashed_at', gmdate('c'));
        if ($target_id > 0) {
            update_term_meta(
                $term_id,
                '_digitalogic_viewer_reassigned_to',
                $target_id
            );
        }
        clean_term_cache($term_id, 'product_cat');
        $context = Live_State::canonical_context_for_actions();
        $canonical_id = (string) (
            $context['canonicalByTerm'][$term_id]
            ?? $entity_id
        );
        return array(
            'entityId' => $canonical_id,
            'eventType' => 'category.deleted',
            'revision' => (string) (
                $context['nodes'][$canonical_id]['revision']
                ?? Revision::category(get_term($term_id, 'product_cat'))
            ),
        );
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private static function mutate_category(
        string $action,
        string $entity_id,
        array $loaded,
        array $payload
    ) {
        $term_id = (int) $loaded['termId'];
        if (
            !in_array($action, array('category.map_source', 'category.unmap_source'), true)
            && in_array((string) $loaded['matchState'], array('suggested', 'unresolved'), true)
        ) {
            return self::error(
                'category_mapping_requires_review',
                'Suggested or unresolved categories are read-only until explicitly mapped.',
                409
            );
        }
        if (
            ($loaded['lifecycleStatus'] ?? 'active') === 'trash'
        ) {
            return self::error(
                'category_is_trashed',
                'Restore the category before editing it.',
                409
            );
        }

        if ($action === 'category.rename') {
            $updated = wp_update_term(
                $term_id,
                'product_cat',
                array('name' => (string) $payload['name'])
            );
            if (is_wp_error($updated)) {
                return $updated;
            }
        } elseif ($action === 'category.move') {
            $parent_id = 0;
            if ($payload['parentId'] !== Live_State::ROOT_CATEGORY_ID) {
                $parent = self::resolve_category(
                    (string) $payload['parentId'],
                    $loaded['context']
                );
                if ($parent instanceof WP_Error) {
                    return $parent;
                }
                if (in_array($parent['matchState'], array('suggested', 'unresolved'), true)) {
                    return self::error(
                        'category_parent_requires_review',
                        'A suggested or unresolved category cannot be a persisted parent.',
                        409
                    );
                }
                $parent_id = (int) $parent['termId'];
            }
            if ($parent_id === $term_id) {
                return self::error('category_cycle', 'A category cannot parent itself.', 409);
            }
            if (
                $parent_id > 0
                && term_is_ancestor_of($term_id, $parent_id, 'product_cat')
            ) {
                return self::error(
                    'category_cycle',
                    'A category cannot move below one of its descendants.',
                    409
                );
            }
            $updated = wp_update_term(
                $term_id,
                'product_cat',
                array('parent' => $parent_id)
            );
            if (is_wp_error($updated)) {
                return $updated;
            }
        } elseif ($action === 'category.map_source') {
            $code = (string) $payload['patrisCode'];
            if (!isset($loaded['context']['canonicalByPatris'][$code])) {
                return self::error('patris_category_not_found', 'Patris category not found.', 404);
            }
            $owners = get_terms(
                array(
                    'taxonomy' => 'product_cat',
                    'hide_empty' => false,
                    'meta_key' => '_digitalogic_patris_category_code',
                    'meta_value' => $code,
                    'fields' => 'ids',
                )
            );
            if (
                !is_wp_error($owners)
                && $owners
                && !in_array($term_id, array_map('intval', $owners), true)
            ) {
                return self::error(
                    'authoritative_mapping_conflict',
                    'A different WooCommerce category owns this authoritative Patris code.',
                    409
                );
            }
            $mapping_revision = Store::put_mapping($code, $term_id);
            if ($mapping_revision instanceof WP_Error) {
                return $mapping_revision;
            }
        } elseif ($action === 'category.unmap_source') {
            if (!Store::delete_mapping((string) $payload['patrisCode'])) {
                return self::error(
                    'reviewed_mapping_not_found',
                    'No reviewed viewer mapping exists for this source code.',
                    404
                );
            }
        }

        $context = Live_State::canonical_context_for_actions();
        $canonical_id = (string) (
            $context['canonicalByTerm'][$term_id]
            ?? Live_State::category_id($term_id)
        );
        $revision = (string) (
            $context['nodes'][$canonical_id]['revision']
            ?? Revision::category(get_term($term_id, 'product_cat'))
        );
        return array('entityId' => $canonical_id, 'revision' => $revision);
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private static function mutate_product(string $action, array $loaded, array $payload)
    {
        /** @var WC_Product $product */
        $product = $loaded['object'];
        if ($product->get_status() === Live_State::PRODUCT_TRASH_STATUS) {
            return self::error(
                'product_is_trashed',
                'Restore the product before editing it.',
                409
            );
        }
        if ($action === 'product.rename') {
            $product->set_name((string) $payload['name']);
        } elseif ($action === 'product.set_status') {
            $product->set_status((string) $payload['status']);
        } elseif ($action === 'product.set_stock_status') {
            $product->set_stock_status((string) $payload['stockStatus']);
        } elseif ($action === 'product.assign_categories') {
            $context = Live_State::canonical_context_for_actions();
            $term_ids = array();
            foreach ($payload['categoryIds'] as $category_id) {
                $resolved = self::resolve_category((string) $category_id, $context);
                if ($resolved instanceof WP_Error) {
                    return $resolved;
                }
                if (in_array($resolved['matchState'], array('suggested', 'unresolved'), true)) {
                    return self::error(
                        'category_mapping_requires_review',
                        'Products cannot be assigned to suggested or unresolved mappings.',
                        409
                    );
                }
                $term_ids[] = (int) $resolved['termId'];
            }
            $product->set_category_ids(array_values(array_unique($term_ids)));
        }
        $product->save();
        return array('revision' => Revision::product($product));
    }

    /**
     * @return array{termId:int,matchState:string,node:array<string,mixed>}|WP_Error
     */
    private static function resolve_category(string $id, array $context)
    {
        $canonical_id = $id;
        foreach ((array) ($context['aliases'] ?? array()) as $alias) {
            if (($alias['aliasId'] ?? '') === $id) {
                $canonical_id = (string) $alias['canonicalId'];
                break;
            }
        }
        $node = $context['nodes'][$canonical_id] ?? null;
        if (!is_array($node)) {
            return self::error('category_not_found', 'Category not found.', 404);
        }
        $preferred_term_id = 0;
        if (preg_match('/^cat:wp:(\d+)$/', $canonical_id, $match)) {
            $preferred_term_id = absint($match[1]);
        } elseif (str_starts_with($canonical_id, 'cat:digitalogic:')) {
            $catalog_key = substr($canonical_id, 4);
            $catalog_terms = get_terms(
                array(
                    'taxonomy' => 'product_cat',
                    'hide_empty' => false,
                    'meta_key' => '_digitalogic_catalog_category_key',
                    'meta_value' => $catalog_key,
                    'fields' => 'ids',
                    'number' => 2,
                )
            );
            if (!is_wp_error($catalog_terms) && count($catalog_terms) === 1) {
                $preferred_term_id = absint($catalog_terms[0]);
            }
        }
        $fallback_term_id = 0;
        foreach ((array) ($node['sourceRefs'] ?? array()) as $source_ref) {
            if (
                is_array($source_ref)
                && ($source_ref['source'] ?? '') === 'woocommerce.product_cat'
            ) {
                $term_id = absint($source_ref['externalId'] ?? 0);
                if ($term_id > 0 && $fallback_term_id === 0) {
                    $fallback_term_id = $term_id;
                }
                if ($term_id > 0 && $term_id === $preferred_term_id) {
                    return array(
                        'termId' => $term_id,
                        'matchState' => (string) ($node['matchState'] ?? 'unresolved'),
                        'node' => $node,
                    );
                }
            }
        }
        if ($fallback_term_id > 0) {
            return array(
                'termId' => $fallback_term_id,
                'matchState' => (string) ($node['matchState'] ?? 'unresolved'),
                'node' => $node,
            );
        }
        return self::error(
            'category_not_mutable',
            'This source-only category must be mapped before it can be changed.',
            409
        );
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private static function safe_payload(
        string $action,
        string $entity_type,
        array $payload
    )
    {
        $shapes = array(
            'category.rename' => array(array('name'), array('name')),
            'category.move' => array(array('parentId'), array('parentId')),
            'category.map_source' => array(array('patrisCode'), array('patrisCode')),
            'category.unmap_source' => array(array('patrisCode'), array('patrisCode')),
            'product.rename' => array(array('name'), array('name')),
            'product.set_status' => array(array('status'), array('status')),
            'product.set_stock_status' => array(
                array('stockStatus'),
                array('stockStatus'),
            ),
            'product.assign_categories' => array(
                array('categoryIds'),
                array('categoryIds'),
            ),
            'order.set_status' => array(array('status'), array('status')),
            'customer.set_segment' => array(array('segment'), array('segment')),
            'entity.trash' => $entity_type === 'category'
                ? array(array('reassignToCategoryId'), array())
                : array(array(), array()),
            'entity.restore' => array(array(), array()),
        );
        $shape = $shapes[$action] ?? null;
        if (
            !is_array($shape)
            || !self::exact_keys($payload, $shape[0], $shape[1])
        ) {
            return self::error(
                'payload_shape_invalid',
                'The action payload must match the exact action shape.',
                400
            );
        }
        if (in_array($action, array('category.rename', 'product.rename'), true)) {
            $name = sanitize_text_field((string) ($payload['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 160) {
                return self::error('name_invalid', 'Name must be 1–160 characters.', 400);
            }
            return array('name' => $name);
        }
        if ($action === 'category.move') {
            $parent_id = trim((string) ($payload['parentId'] ?? ''));
            if (!preg_match('/^cat:[A-Za-z0-9._:%-]+$/', $parent_id)) {
                return self::error('parent_id_invalid', 'Parent category is invalid.', 400);
            }
            return array('parentId' => $parent_id);
        }
        if (in_array($action, array('category.map_source', 'category.unmap_source'), true)) {
            $code = Store::patris_code((string) ($payload['patrisCode'] ?? ''));
            if ($code === '') {
                return self::error('patris_code_invalid', 'Patris code is invalid.', 400);
            }
            return array('patrisCode' => $code);
        }
        if ($action === 'product.set_status') {
            $status = sanitize_key((string) ($payload['status'] ?? ''));
            if (!in_array($status, array('publish', 'draft', 'pending', 'private'), true)) {
                return self::error('product_status_invalid', 'Product status is invalid.', 400);
            }
            return array('status' => $status);
        }
        if ($action === 'product.set_stock_status') {
            $status = sanitize_key((string) ($payload['stockStatus'] ?? ''));
            if (!in_array($status, array('instock', 'outofstock', 'onbackorder'), true)) {
                return self::error('stock_status_invalid', 'Stock status is invalid.', 400);
            }
            return array('stockStatus' => $status);
        }
        if ($action === 'product.assign_categories') {
            if (!is_array($payload['categoryIds'])) {
                return self::error(
                    'category_ids_invalid',
                    'categoryIds must be an array.',
                    400
                );
            }
            $ids = array_values(
                array_unique(
                    array_slice(
                        array_filter(
                            array_map(
                                static fn($value): string => trim((string) $value),
                                (array) ($payload['categoryIds'] ?? array())
                            ),
                            static fn(string $value): bool =>
                                (bool) preg_match('/^cat:[A-Za-z0-9._:%-]+$/', $value)
                        ),
                        0,
                        100
                    )
                )
            );
            if (!$ids) {
                return self::error('category_ids_invalid', 'At least one category is required.', 400);
            }
            return array('categoryIds' => $ids);
        }
        if ($action === 'order.set_status') {
            $status = sanitize_key((string) ($payload['status'] ?? ''));
            if (!in_array($status, Live_State::mutable_order_statuses(), true)) {
                return self::error('order_status_invalid', 'Order status is not mutable.', 400);
            }
            return array('status' => $status);
        }
        if ($action === 'customer.set_segment') {
            $segment = sanitize_key((string) ($payload['segment'] ?? ''));
            if (!in_array($segment, Live_State::customer_segments(), true)) {
                return self::error('customer_segment_invalid', 'Customer segment is invalid.', 400);
            }
            return array('segment' => $segment);
        }
        if ($action === 'entity.trash') {
            $target = trim((string) ($payload['reassignToCategoryId'] ?? ''));
            if ($target === '') {
                return array();
            }
            if (!preg_match('/^cat:[A-Za-z0-9._:%-]+$/', $target)) {
                return self::error(
                    'reassignment_target_invalid',
                    'Reassignment category is invalid.',
                    400
                );
            }
            return array('reassignToCategoryId' => $target);
        }
        if ($action === 'entity.restore') {
            return array();
        }
        return self::error('payload_invalid', 'Action payload is invalid.', 400);
    }

    /**
     * @param string[] $allowed
     * @param string[] $required
     */
    private static function exact_keys(
        array $value,
        array $allowed,
        array $required
    ): bool {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                return false;
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return string|WP_Error
     */
    private static function expected_revision(WP_REST_Request $request, array $body)
    {
        $header = trim((string) $request->get_header('If-Match'));
        $header = trim($header, " \t\n\r\0\x0B\"");
        $body_revision = trim((string) ($body['revision'] ?? ''));
        if ($header === '' || !preg_match('/^rev:[a-f0-9]{64}$/', $header)) {
            return self::error(
                'if_match_required',
                'A current If-Match revision is required.',
                428
            );
        }
        if ($body_revision !== '' && !hash_equals($header, $body_revision)) {
            return self::error(
                'revision_headers_disagree',
                'The body revision and If-Match header disagree.',
                400
            );
        }
        return $header;
    }

    private static function error(
        string $code,
        string $message,
        int $status,
        array $data = array()
    ): WP_Error {
        return new WP_Error(
            'digitalogic_viewer_' . sanitize_key($code),
            __($message, 'digitalogic-viewer-bridge'),
            array_merge(array('status' => $status), $data)
        );
    }
}
