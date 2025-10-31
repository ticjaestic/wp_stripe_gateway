<?php

/**
 * WPAlmomento Endpoints
 *
 * @class    SG_Endpoints
 * @package  WPAlmomento\Endpoints
 * @version  1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * SG_Endpoints class.
 */
class SG_Endpoints {

	public function __construct() {
		add_action('rest_api_init', array($this, 'sg_register_api_route'));
	}

    function sg_check_stripe_customer_id($request) {
        $user_id = get_current_user_id();

        if(empty($user_id)){
            return array(
                'status' => false,
                'client_secret' => null,
                'message' => __( "User ID 'user_id' is required.", 'wc-rest-payment' )
            );
        }

        return array(
            'status' => true,
            'message' => __( "The stripe client has already been previously generated.", 'wc-rest-payment' )
        );
    }
    
    function sg_create_user_account($request) {
        try {
            $parameters 	= $request->get_params();
            $username       = sanitize_text_field($parameters['username']);
            $email          = sanitize_text_field($parameters['email']);
            $password       = sanitize_text_field($parameters['password']);
            $repassword     = sanitize_text_field($parameters['repassword']);

            if(empty($username) || empty($email) || empty($password) || empty($repassword)){
                return array(
                    'status' => false,
                    'message' => __( "All form fields are required.", 'wc-rest-payment' )
                );
            }

            if($password != $repassword) {
                return array(
                    'status' => false,
                    'message' => __( "Password confirmation does not match.", 'wc-rest-payment' )
                );
            }

            try {
                $userId = wc_create_new_customer($email, $username, $password);
                
                if($userId) {
                    return array(
                        'status' => true,
                        'response' => $user,
                        'message' => null
                    );
                }
            } catch (Exception $e) {
                return array(
                    'status' => false,
                    'message' => $e->getMessage()
                );
            }
        } catch (Exception $e) {    
            return array(
                'status' => false,
                'message' => $e->getMessage()
            );
        }
    }

    function getCatTermById($catId) {
        $product_term_args = array(
            'taxonomy' => 'product_cat',
            'include' => $catId,
            'orderby'  => 'include'
        );
        $product_terms = get_terms($product_term_args);

        $product_term_slugs = [];
        foreach ($product_terms as $product_term) {
            $product_term_slugs[] = $product_term->slug;
        }

        return $product_term_slugs;
    }

    function sg_get_products($request) {
        $parameters     = $request->get_params();
        
        // isset() para evitar errores Undefined index
        $category       = isset($parameters['category']) ? sanitize_text_field($parameters['category']) : null;
        $onSale         = isset($parameters['on_sale']) ? sanitize_text_field($parameters['on_sale']) : null;
        $featured       = isset($parameters['featured']) ? sanitize_text_field($parameters['featured']) : null;
        $orderBy        = isset($parameters['orderby']) ? sanitize_text_field($parameters['orderby']) : 'date';
        $perPage        = isset($parameters['per_page']) ? intval($parameters['per_page']) : 20;
        $page           = isset($parameters['page']) ? intval($parameters['page']) : 1;
        $search         = isset($parameters['search']) ? sanitize_text_field($parameters['search']) : null;
        $product_ids    = isset($parameters['include']) ? array_map('sanitize_text_field', explode(',', $parameters['include'])) : [];

        $categoryTerm = $category ? $this->getCatTermById($category) : null;

        $args = array(
            'orderby'       => $orderBy,
            'post_status'   => 'publish',
            'stock_status'  => 'instock',
            'limit'         => $perPage,
            'page'          => $page,
            'paginate'      => true,  // activa la paginación
            'return'        => 'objects'
        );

        if (!empty($product_ids)) {
            $args['include'] = $product_ids;
        } else {
            if ($categoryTerm) $args['category'] = $categoryTerm;
            if ($onSale) {
                $sales_ids = wc_get_product_ids_on_sale();
                $args['include'] = $sales_ids;
            }
            if ($featured) $args['featured'] = true;
            if ($search) $args['s'] = $search;
        }

        // Con paginate=true, wc_get_products devuelve un objeto con ->products
        $results = wc_get_products( $args );
        $products = $results->products;  // Acceder a ->products
        $simplified_data = array();

        foreach ($products as $key => $single_product_data) {
            $data = $single_product_data->get_data();
            $simplified_data[$key] = $data;
            $simplified_data[$key]['image'] = wp_get_attachment_image_url($data['image_id'], 'full');

            if ($single_product_data->is_type('variable')) {
                $variation_ids = $single_product_data->get_children();
                $variations = array();
        
                foreach ($variation_ids as $variation_id) {
                    $variation = new WC_Product_Variation($variation_id);
                    $variation_data = $variation->get_data();
                    $variation_data['image'] = wp_get_attachment_image_url($data['image_id'], 'full');
                    $variations[] = $variation_data;
                }
        
                $simplified_data[$key]['variations'] = $variations;
            }
        }

        // Devolver con información de paginación
        return array(
            'status' => true,
            'results' => $simplified_data,
            'pagination' => array(
                'total'         => $results->total,
                'total_pages'   => $results->max_num_pages,
                'current_page'  => $page,
                'per_page'      => $perPage
            )
        );
    }

    function sg_get_products_by_id($request) {
        // Obtener el parámetro 'productIds' del request.
        $product_ids = $request->get_param('productIds');
    
        // Validar que el parámetro sea un array y no esté vacío.
        if (!is_array($product_ids) || empty($product_ids)) {
            return rest_ensure_response([
                'error' => 'Invalid or missing "productIds" parameter.'
            ], 400);
        }
    
        $simplified_data = [];
    
        foreach ($product_ids as $product_id) {
            // Intentar obtener el producto.
            $product = wc_get_product($product_id);
    
            // Si no se encuentra, intentar cargarlo como variación.
            if (!$product) {
                if ('product_variation' === get_post_type($product_id)) {
                    $product = new WC_Product_Variation($product_id);
                } else {
                    continue; // Saltar si el ID no es válido.
                }
            }
    
            // Obtener los datos básicos del producto o variación.
            $product_data = $product->get_data();

            if ($product->is_type('variation')) {
                $parent_id = $product->get_parent_id();
                $parent_product = wc_get_product($parent_id);
    
                // Obtener la imagen del producto padre si no hay imagen en la variación.
                if ($parent_product) {
                    $parent_image = wp_get_attachment_image_url($parent_product->get_image_id(), 'full');
                    $product_data['image'] = wp_get_attachment_image_url($product->get_image_id(), 'full') ?: $parent_image;
    
                    // Añadir datos base del producto padre.
                    $product_data['parent'] = [
                        'id'       => $parent_id,
                        'name'     => $parent_product->get_name(),
                        'image'    => $parent_image,
                        'price'    => $parent_product->get_price(),
                        'sku'      => $parent_product->get_sku(),
                        'category' => wp_get_post_terms($parent_id, 'product_cat', ['fields' => 'names']),
                    ];
                }
            } else {
                // Si es un producto regular, agregar su imagen directamente.
                $product_data['image'] = wp_get_attachment_image_url($product->get_image_id(), 'full');
            }

            // Si es un producto variable, agregar sus variaciones.
            if ($product->is_type('variable')) {
                $variation_ids = $product->get_children();
                $variations = [];
    
                foreach ($variation_ids as $variation_id) {
                    $variation = new WC_Product_Variation($variation_id);
                    $variation_data = $variation->get_data();
                    $variation_data['image'] = wp_get_attachment_image_url($variation_data['image_id'], 'full');
                    $variations[] = $variation_data;
                }
    
                $product_data['variations'] = $variations;
            }
    
            // Si es una variación, agregar el ID del producto padre.
            if ($product->is_type('variation')) {
                $product_data['parent_id'] = $product->get_parent_id();
            }
    
            // Agregar el producto o variación procesado al array de resultados.
            $simplified_data[] = $product_data;
        }
    
        // Devolver la respuesta con los productos simplificados.
        return rest_ensure_response([
            'status' => true,
            'results' => $simplified_data
        ]);
    }

    function sg_create_order($request) {
        $parameters 	= $request->get_params();
        $billing        = $parameters['billing'];
        $shipping       = $parameters['shipping'];
        $customerId     = $parameters['customer_id'];
        $lineItems      = $parameters['line_items'];
        $paymentMethod  = $parameters['payment_method'];
        $setPaid        = $parameters['set_paid'];
        $paymentMethodTitle  = $parameters['payment_method_title'];
        $shippingLines  = $parameters['shipping_lines'];
        $couponLines    = $parameters['coupon_lines'];
        $notes          = $parameters['notes'];

        $shippingOrder = new WC_Order_Item_Shipping();
        $shippingOrder->set_method_title( $shippingLines[0]['method_title'] );
        $shippingOrder->set_method_id( $shippingLines[0]['method_id'] );
        $shippingOrder->set_total( floatval($shippingLines[0]['total']) );

        $order = wc_create_order();

        for ($i=0; $i < count($lineItems); $i++) { 
            $order->add_product(  get_product($lineItems[$i]['product_id']), $lineItems[$i]['quantity'] );
        }

        if($notes) {
            $comment_data = array(
                'comment_post_ID' => $order->id,
                'comment_author' => 'Sistema',
                'comment_author_email' => 'no-reply@almomento.cat',
                'comment_content' => $notes,
                'comment_type' => 'order_note',
                'comment_approved' => 1,
            );
            
            // Insertar el comentario en la base de datos.
            wp_insert_comment($comment_data);
        }

        $order->set_customer_id( 1 );
        $order->set_address( $billing, 'billing' );
        $order->set_address( $shipping, 'shipping' );
        $order->add_item($shippingOrder);
        $order->set_customer_id($customerId);
        $order->set_payment_method( $paymentMethod );
        $order->set_payment_method_title( $paymentMethodTitle );
        $order->apply_coupon($couponLines[0]['code']);
        $order->calculate_totals();
        
        return json_encode($order->get_data());
    }
    
    function sg_register_api_route() {
        register_rest_route('stripe-payment-gateway/v1', '/check-authentication', array(
            'methods' => 'GET',
            'callback' => array($this, 'sg_check_stripe_customer_id'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('stripe-payment-gateway/v1', '/users', array(
            'methods' => 'POST',
            'callback' => array($this, 'sg_create_user_account'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('stripe-payment-gateway/v1', '/products', array(
            'methods' => 'GET',
            'callback' => array($this, 'sg_get_products'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('stripe-payment-gateway/v1', '/products-by-id', array(
            'methods' => 'POST',
            'callback' => array($this, 'sg_get_products_by_id'),
            'permission_callback' => '__return_true'
        ));

        register_rest_route('stripe-payment-gateway/v1', '/create-order', array(
            'methods' => 'POST',
            'callback' => array($this, 'sg_create_order'),
            'permission_callback' => '__return_true'
        ));
    }

}
