<?php namespace Admin\Models;

use Admin\Classes\PaymentGateways;
use Carbon\Carbon;
use DB;
use Igniter\Flame\Location\Models\Location;
use Model;
use Request;

/**
 * Orders Model Class
 *
 * @package Admin
 */
class Orders_model extends Model
{
    const CREATED_AT = 'date_added';

    const UPDATED_AT = 'date_modified';

    const DELIVERY = 'delivery';

    const COLLECTION = 'collection';

    protected static $orderTypes = [1 => self::DELIVERY, 2 => self::COLLECTION];

    /**
     * @var string The database table name
     */
    protected $table = 'orders';

    /**
     * @var string The database table primary key
     */
    protected $primaryKey = 'order_id';

    protected $guarded = ['*'];

    protected $fillable = ['customer_id', 'first_name', 'last_name', 'email', 'telephone', 'location_id', 'address_id', 'cart',
        'total_items', 'comment', 'payment', 'order_type', 'date_added', 'date_modified', 'order_time', 'order_date', 'order_total',
        'status_id', 'ip_address', 'user_agent', 'notify', 'assignee_id', 'invoice_no', 'invoice_prefix', 'invoice_date',
    ];

    protected $timeFormat = 'H:i';

    /**
     * @var array The model table column to convert to dates on insert/update
     */
    public $timestamps = TRUE;

    public $casts = [
        'cart'       => 'serialize',
        'order_date' => 'date',
        'order_time' => 'time',
    ];

    public $relation = [
        'belongsTo' => [
            'customer'       => 'Admin\Models\Customers_model',
            'location'       => 'Admin\Models\Locations_model',
            'address'        => 'Admin\Models\Addresses_model',
            'status'         => 'Admin\Models\Statuses_model',
            'assignee'       => 'Admin\Models\Staffs_model',
            'payment_method' => ['Admin\Models\Payments_model', 'foreignKey' => 'payment', 'otherKey' => 'code'],
            'payment_logs'   => 'Admin\Models\Payment_logs_model',
        ],
        'morphMany' => [
            'review'         => ['Admin\Models\Reviews_model'],
            'status_history' => ['Admin\Models\Status_history_model', 'name' => 'object'],
        ],
    ];

    public $availablePayments;

    public static $allowedSortingColumns = [
        'order_id asc', 'order_id desc',
        'date_added asc', 'date_added desc',
    ];

    //
    // Events
    //

    public function beforeCreate()
    {
        $this->generateHash();

        $this->ip_address = Request::getClientIp();
        $this->user_agent = Request::userAgent();
    }

    //
    // Scopes
    //

    public function scopeListFrontEnd($query, $options = [])
    {
        extract(array_merge([
            'page'      => 1,
            'pageLimit' => 20,
            'customer'  => null,
            'location'  => null,
            'sort'      => 'address_id desc',
        ], $options));

        if ($location instanceof Location) {
            $query->where('location_id', $location->getKey());
        }
        else if (strlen($location)) {
            $query->where('location_id', $location);
        }

        if ($customer instanceof Customers_model) {
            $query->where('customer_id', $customer->getKey());
        }
        else if (strlen($customer)) {
            $query->where('customer_id', $customer);
        }

        if (!is_array($sort)) {
            $sort = [$sort];
        }

        foreach ($sort as $_sort) {
            if (in_array($_sort, self::$allowedSortingColumns)) {
                $parts = explode(' ', $_sort);
                if (count($parts) < 2) {
                    array_push($parts, 'desc');
                }
                list($sortField, $sortDirection) = $parts;
                $query->orderBy($sortField, $sortDirection);
            }
        }

        return $query->paginate($pageLimit, $page);
    }

    //
    // Accessors & Mutators
    //

    public function getCustomerNameAttribute($value)
    {
        return $this->first_name.' '.$this->last_name;
    }

    public function getOrderDateTimeAttribute($value)
    {
        return Carbon::createFromFormat(
            'Y-m-d H:i:s',
            "{$this->attributes['order_date']} {$this->attributes['order_time']}"
        );
    }

    public function getOrderTypeAttribute($value)
    {
        if (isset(self::$orderTypes[$value]))
            return self::$orderTypes[$value];

        return $value;
    }

    public function getOrderTypeNameAttribute()
    {
        return ucwords($this->order_type);
    }

    //
    // Helpers
    //

    public function getStatusColor()
    {
        $status = $this->status()->first();
        if (!$status)
            return null;

        return $status->status_color;
    }

    public function getAddressOptions()
    {
        return Addresses_model::selectRaw('*, concat_ws(" ", address_1, city, postcode) AS display_name')
                              ->where('customer_id', $this->customer_id)
                              ->dropdown('display_name');
    }

    public function getStatusOptions()
    {
        return $this->newQuery()->status()->isForOrder()->get();
    }

    public function getPaymentOptions()
    {
        if (!is_null($this->availablePayments))
            return $this->availablePayments;

        $this->availablePayments = [];
        $payments = \Admin\Classes\PaymentGateways::instance()->listGateways();
        foreach ($payments as $payment) {
            $this->availablePayments[$payment['code']] = !empty($payment['name'])
                ? lang($payment['name']) : $payment['code'];
        }

        return $this->availablePayments;
    }

    public function isDeliveryType()
    {
        return $this->order_type == static::DELIVERY;
    }

    public function isCollectionType()
    {
        return $this->order_type == static::COLLECTION;
    }

    /**
     * Generate a unique hash for this order.
     * @return string
     */
    protected function generateHash()
    {
        $this->hash = $this->createHash();
        while ($this->newQuery()->where('hash', $this->hash)->count() > 0) {
            $this->hash = $this->createHash();
        }
    }

    /**
     * Create a hash for this order.
     * @return string
     */
    protected function createHash()
    {
        return md5(uniqid('order', microtime()));
    }

    /**
     * Find a single order by order_id
     *
     * @param int $order_id
     * @param int $customer_id
     *
     * @return bool|object
     */
//    public function getOrder($order_id = null, $customer_id = null)
//    {
//        if (!empty($order_id)) {
//            if (!empty($customer_id)) {
//                $this->where('customer_id', $customer_id);
//            }
//
//            return $this->joinTables()->findOrNew($order_id)->toArray();
//        }
//
//        return $order_id;
//    }

    /**
     * Find a single invoice by order_id
     *
     * @param int $order_id
     *
     * @return bool|object
     */
//    public function getInvoice($order_id = null)
//    {
//        if (!empty($order_id) AND is_numeric($order_id)) {
//            return $this->joinTables()->findOrNew($order_id)->toArray();
//        }
//
//        return FALSE;
//    }

    /**
     * Find a single order by order_id during checkout
     *
     * @param int $order_id
     * @param int $customer_id
     *
     * @return bool|object
     */
//    public function getCheckoutOrder($order_id, $customer_id)
//    {
//        if (isset($order_id, $customer_id)) {
//            return $this->where('customer_id', $customer_id)
//                        ->where('status_id', null)->findOrNew($order_id)->toArray();
//        }
//
//        return FALSE;
//    }

    /**
     * Return all order menu by order_id
     *
     * @param int $order_id
     *
     * @return array
     */
    public function getOrderMenus()
    {
        return DB::table('order_menus')->where('order_id', $this->getKey())->get();
    }

    /**
     * Return all order menu options by order_id
     *
     * @param int $order_id
     *
     * @return array
     */
    public function getOrderMenuOptions()
    {
        return DB::table('order_options')->where('order_id', $this->getKey())->get();
    }

    /**
     * Return all order totals by order_id
     *
     * @param int $order_id
     *
     * @return array
     */
    public function getOrderTotals()
    {
        return DB::table('order_totals')->where('order_id', $this->getKey())->orderBy('priority')->get();
    }

    /**
     * Find a single used coupon by order_id
     *
     * @param int $order_id
     *
     * @return mixed
     */
    public function getOrderCoupon()
    {
        return Coupons_history_model::where('order_id', $this->getKey())->first();
    }

    /**
     * Return the dates of all orders
     *
     * @return array
     */
    public function getOrderDates()
    {
        return $this->pluckDates('date_added');
    }

    /**
     * Check if an order was successfully placed
     *
     * @param int $order_id
     *
     * @return bool TRUE on success, or FALSE on failure
     */
    public function isPlaced()
    {
        return $this->status_id;
    }

    /**
     * Update an existing order by order_id
     *
     * @param int $order_id
     * @param array $update
     *
     * @return bool
     */
    public function updateOrder()
    {
        if (!is_numeric($order_id)) return FALSE;
//        $this->sendConfirmationMail();

        if (isset($update['order_status']) AND !isset($update['status_id'])) {
            $update['status_id'] = $update['order_status'];
        }

        $statusesModel = Statuses_model::make();
        if ($query = $this->find($order_id)->fill($update)->update()) {
            $status = $statusesModel->getStatus($update['status_id']);

            // Make sure order status has not been previously updated
            // to one of the processing order status. If so,
            // skip to avoid updating stock twice.
            $processing_status_exists = $statusesModel->statusExists('order', $order_id, setting('processing_order_status'));

            if (!$processing_status_exists AND in_array($update['status_id'], (array)setting('processing_order_status'))) {
                $this->subtractStock($order_id);

                Coupons_model::redeemCoupon($order_id);
            }

            if (isset($update['status_notify']) AND $update['status_notify'] == '1') {
                $mail_data = $this->getMailData($order_id);

                $mail_data['status_name'] = $status['status_name'];
                $mail_data['status_comment'] = !empty($update['status_comment']) ? $update['status_comment'] : lang('admin::orders.text_no_comment');

                $mail_template = Mail_templates_model::getDefaultTemplateData('order_update');
                $update['status_notify'] = $this->sendMail($mail_data['email'], $mail_template, $mail_data);
            }

            $this->addStatusHistory($order_id, $update, $status);

            if (setting('auto_invoicing') == '1' AND in_array($update['status_id'], (array)setting('completed_order_status'))) {
                $this->createInvoiceNo($order_id);
            }
        }

        return $query;
    }

    /**
     * Generate and save an invoice number
     *
     * @param int $order_id
     *
     * @return bool|string invoice number on success, or FALSE on failure
     */
    public function createInvoiceNo($order_id = null)
    {

        $order_status_exists = $statusesModel->statusExists('order', $order_id, setting('completed_order_status'));
        if ($order_status_exists !== TRUE) return TRUE;

        $order_info = $this->getOrder($order_id);

        if ($order_info AND empty($order_info['invoice_no'])) {
            $order_info['invoice_prefix'] = str_replace('{year}', date('Y'), str_replace('{month}', date('m'), str_replace('{day}', date('d'), setting('invoice_prefix'))));

            $this;
            $row = $this->selectRaw('MAX(invoice_no)')->where('invoice_prefix', $order_info['invoice_prefix'])->first();

            $invoice_no = !empty($row['invoice_no']) ? $row['invoice_no'] + 1 : 1;

            if ($orderModel = $this->find($order_id)) {
                $orderModel->update([
                    'invoice_prefix' => $order_info['invoice_prefix'],
                    'invoice_no'     => $invoice_no,
                    'invoice_date'   => mdate('%Y-%m-%d %H:%i:%s', time()),
                ]);
            }

            return $order_info['invoice_prefix'].$invoice_no;
        }

        return FALSE;
    }

    /**
     * Create a new order
     *
     * @param array $order_info
     * @param array $cart_contents
     *
     * @return bool|int order_id on success, FALSE on failure
     */
//    public function addOrder($order_info = [], $cart_contents = [])
//    {
//        if (empty($order_info) OR empty($cart_contents)) return FALSE;
//
//        if (isset($order_info['order_time'])) {
//            $current_time = time();
//            $order_time = (strtotime($order_info['order_time']) < strtotime($current_time)) ? $current_time : $order_info['order_time'];
//            $order_info['order_time'] = mdate('%H:%i', strtotime($order_time));
//            $order_info['order_date'] = mdate('%Y-%m-%d', strtotime($order_time));
//            $order_info['ip_address'] = $this->input->ip_address();
//            $order_info['user_agent'] = $this->input->user_agent();
//        }
//
//        $order_info['status_id'] = $order_info['notify'] = $order_info['assignee_id'] = $order_info['invoice_no'] = $order_info['invoice_prefix'] = $order_info['invoice_date'] = '';
//
//        $order_info['cart'] = $cart_contents;
//        if (isset($cart_contents['order_total'])) {
//            $order_info['order_total'] = $cart_contents['order_total'];
//        }
//
//        if (isset($cart_contents['total_items'])) {
//            $order_info['total_items'] = $cart_contents['total_items'];
//        }
//
//        $order_id = (isset($order_info['order_id']) AND is_numeric($order_info['order_id'])) ? $order_info['order_id'] : null;
//
//        $orderModel = $this->findOrNew($order_id);
//
//        if ($saved = $orderModel->fill($order_info)->save()) {
//            $order_id = $orderModel->getKey();
//
//            if (isset($order_info['address_id'])) {
//                Addresses_model::updateDefault($order_info['customer_id'], $order_info['address_id']);
//            }
//
//            $this->addOrderMenus($order_id, $cart_contents);
//
//            $this->addOrderTotals($order_id, $cart_contents);
//
//            if (!empty($cart_contents['totals']['coupon'])) {
//                $this->addOrderCoupon($order_id, $order_info['customer_id'], $cart_contents['totals']['coupon']);
//            }
//
//            return $order_id;
//        }
//    }

    /**
     * Complete order by sending email confirmation and,
     * updating order status
     *
     * @param int $order_id
     * @param array $order_info
     * @param array $cart_contents
     *
     * @return bool
     */
    public function completeOrder($status)
    {
        if (!$status instanceof Statuses_model)
            return FALSE;

        $this->status_id = $status->getKey();
        $this->save();
    }

    /**
     * Add cart menu items to order by order_id
     *
     * @param array $cartContent
     *
     * @return bool
     */
    public function addOrderMenus($cartContent = [])
    {
        $orderId = $this->getKey();
        if (!is_numeric($orderId))
            return FALSE;

        DB::table('order_menus')->where('order_id', $orderId)->delete();
        DB::table('order_options')->where('order_id', $orderId)->delete();

        foreach ($cartContent as $rowId => $cartItem) {
            if ($rowId != $cartItem->rowId) continue;

            $orderMenuId = DB::table('order_menus')->insertGetId([
                'order_id'      => $orderId,
                'menu_id'       => $cartItem->id,
                'name'          => $cartItem->name,
                'quantity'      => $cartItem->qty,
                'price'         => $cartItem->price,
                'subtotal'      => $cartItem->subtotal,
                'comment'       => $cartItem->comment,
                'option_values' => serialize($cartItem->options),
            ]);

            if ($orderMenuId AND count($cartItem->options)) {
                $this->addOrderMenuOptions($orderMenuId, $cartItem->id, $cartItem->options);
            }
        }
    }

    /**
     * Add cart menu item options to menu and order by,
     * order_id and menu_id
     *
     * @param $orderMenuId
     * @param $menuId
     * @param $options
     *
     * @return bool
     */
    protected function addOrderMenuOptions($orderMenuId, $menuId, $options)
    {
        $orderId = $this->getKey();
        if (!is_numeric($orderId))
            return FALSE;

        foreach ($options as $option) {
            foreach ($option['values'] as $value) {
                DB::table('order_options')->insert([
                    'order_menu_id'        => $orderMenuId,
                    'order_id'             => $orderId,
                    'menu_id'              => $menuId,
                    'order_menu_option_id' => $option['menu_option_id'],
                    'menu_option_value_id' => $value['menu_option_value_id'],
                    'order_option_name'    => $value['name'],
                    'order_option_price'   => $value['price'],
                ]);
            }
        }
    }

    /**
     * Add cart totals to order by order_id
     *
     * @param array $totals
     *
     * @return bool
     */
    public function addOrderTotals($totals = [])
    {
        $orderId = $this->getKey();
        if (!is_numeric($orderId))
            return FALSE;

        DB::table('order_totals')->where('order_id', $orderId)->delete();

        foreach ($totals as $total) {
            DB::table('order_totals')->insert([
                'order_id' => $orderId,
                'code'     => $total['name'],
                'title'    => $total['label'],
                'value'    => $total['value'],
                'priority' => $total['priority'],
            ]);
        }
    }

    /**
     * Add cart coupon to order by order_id
     *
     * @param int $order_id
     * @param int $customer_id
     * @param array $coupon
     *
     * @return int|bool
     */
    public function addOrderCoupon($order_id, $customer_id, $coupon)
    {
        if (is_array($coupon) AND is_numeric($coupon['amount'])) {
            $this->load->model('Coupons_model');
            $this->load->model('Coupons_history_model');

            $this->Coupons_history_model->where('order_id', $order_id)->delete();

            $temp_coupon = $this->Coupons_model->getCouponByCode($coupon['code']);

            $insert = [
                'order_id'    => $order_id,
                'customer_id' => empty($customer_id) ? '0' : $customer_id,
                'coupon_id'   => $temp_coupon['coupon_id'],
                'code'        => $temp_coupon['code'],
                'amount'      => '-'.$coupon['amount'],
                'min_total'   => $temp_coupon['min_total'],
                'date_used'   => mdate('%Y-%m-%d %H:%i:%s', time()),
            ];

            return $this->Coupons_history_model->insertGetId($insert);
        }
    }

    /**
     * Add order status to status history
     *
     * @param int $order_id
     * @param array $update
     * @param array $status
     *
     * @return mixed
     */
    protected function addStatusHistory($order_id, $update, $status)
    {
        $status_update = [];
        if (APPDIR === ADMINDIR) {
            $status_update['staff_id'] = AdminAuth::getStaffId();
        }

        $status_update['object_id'] = (int)$order_id;
        $status_update['status_id'] = (int)$update['status_id'];
        $status_update['comment'] = isset($update['status_comment']) ? $update['status_comment'] : $status['status_comment'];
        $status_update['notify'] = isset($update['status_notify']) ? $update['status_notify'] : $status['notify_customer'];
        $status_update['date_added'] = mdate('%Y-%m-%d %H:%i:%s', time());

        return Statuses_model::addStatusHistory('order', $status_update);
    }

    /**
     * Subtract cart item quantity from menu stock quantity
     *
     * @param int $order_id
     *
     * @return bool
     */
    public function subtractStock()
    {
        foreach ($this->getOrderMenus() as $orderMenu) {
            if ($menu = Menus_model::find($orderMenu->menu_id))
                $menu->updateStock($orderMenu->quantity, 'subtract');
        }
    }

    /**
     * Send the order confirmation email
     *
     * @param int $order_id
     *
     * @return string 0 on failure, or 1 on success
     */
    public function sendConfirmationMail($order_id)
    {
        $this->load->model('Mail_layouts_model');

        $mail_data = $this->getMailData($order_id);
        $config_order_email = is_array(setting('order_email')) ? setting('order_email') : [];

        $notify = '0';
        if (setting('customer_order_email') == '1' OR in_array('customer', $config_order_email)) {
            $mail_template = $this->Mail_templates_model->getDefaultTemplateData('order');
            $notify = $this->sendMail($mail_data['email'], $mail_template, $mail_data);
        }

        if (!empty($mail_data['location_email']) AND (setting('location_order_email') == '1' OR in_array('location', $config_order_email))) {
            $mail_template = $this->Mail_templates_model->getDefaultTemplateData('order_alert');
            $this->sendMail($mail_data['location_email'], $mail_template, $mail_data);
        }

        if (in_array('admin', $config_order_email)) {
            $mail_template = $this->Mail_templates_model->getDefaultTemplateData('order_alert');
            $this->sendMail(setting('site_email'), $mail_template, $mail_data);
        }

        return $notify;
    }

    /**
     * Return the order data to build mail template
     *
     * @param int $order_id
     *
     * @return array
     */
    public function getMailData($order_id)
    {
        $data = [];

        if ($result = $this->getOrder($order_id)) {
            $this->load->library('country');

            $data['order_number'] = $result['order_id'];
            $data['order_view_url'] = site_url('account/orders/view/'.$result['order_id']);
            $data['order_type'] = ($result['order_type'] == '1') ? 'delivery' : 'collection';
            $data['order_time'] = mdate('%H:%i', strtotime($result['order_time'])).' '.mdate('%d %M', strtotime($result['order_date']));
            $data['order_date'] = mdate('%d %M %y', strtotime($result['date_added']));
            $data['first_name'] = $result['first_name'];
            $data['last_name'] = $result['last_name'];
            $data['email'] = $result['email'];
            $data['telephone'] = $result['telephone'];
            $data['order_comment'] = $result['comment'];

            $payments = PaymentGateways::instance()->listGateways();
            if (isset($payments[$result['payment']]) AND $payment = $payments[$result['payment']]) {
                $data['order_payment'] = !empty($payment['name']) ? $this->lang->line($payment['name']) : $payment['code'];
            }
            else {
                $data['order_payment'] = lang('admin::orders.text_no_payment');
            }

            $data['order_menus'] = [];
            $menus = $this->getOrderMenus($result['order_id']);
            $options = $this->getOrderMenuOptions($result['order_id']);
            if ($menus) {
                foreach ($menus as $menu) {
                    $option_data = [];

                    if (!empty($options)) {
                        foreach ($options as $key => $option) {
                            if ($menu['order_menu_id'] == $option['order_menu_id']) {
                                $option_data[] = $option['order_option_name'].lang('admin::orders.text_equals').$this->currency->format($option['order_option_price']);
                            }
                        }
                    }

                    $data['order_menus'][] = [
                        'menu_name'     => $menu['name'],
                        'menu_quantity' => $menu['quantity'],
                        'menu_price'    => $this->currency->format($menu['price']),
                        'menu_subtotal' => $this->currency->format($menu['subtotal']),
                        'menu_options'  => implode('<br /> ', $option_data),
                        'menu_comment'  => $menu['comment'],
                    ];
                }
            }

            $data['order_totals'] = [];
            $order_totals = $this->getOrderTotals($result['order_id']);
            if ($order_totals) {
                foreach ($order_totals as $total) {
                    $data['order_totals'][] = [
                        'order_total_title' => htmlspecialchars_decode($total['title']),
                        'order_total_value' => $this->currency->format($total['value']),
                        'priority'          => $total['priority'],
                    ];
                }
            }

            $data['order_address'] = lang('admin::orders.text_collection_order_type');
            if (!empty($result['address_id'])) {
                $this->load->model('Addresses_model');
                $order_address = $this->Addresses_model->getAddress($result['customer_id'], $result['address_id']);
                $data['order_address'] = $this->country->addressFormat($order_address);
            }

            if (!empty($result['location_id'])) {
                $this->load->model('Locations_model');
                $location = $this->Locations_model->getLocation($result['location_id']);
                $data['location_name'] = $location['location_name'];
                $data['location_email'] = $location['location_email'];
            }
        }

        return $data;
    }

    /**
     * Send an email
     *
     * @param int $email
     * @param array $mail_template
     * @param array $mail_data
     *
     * @return bool|string
     */
    public function sendMail($email, $mail_template = [], $mail_data = [])
    {
        if (empty($mail_template) OR !isset($mail_template['subject'], $mail_template['body']) OR empty($mail_data)) {
            return FALSE;
        }

        $this->load->library('email');

        $this->email->initialize();

        if (!empty($mail_data['status_comment'])) {
            $mail_data['status_comment'] = $this->email->parse_template($mail_data['status_comment'], $mail_data);
        }

        $this->email->from(setting('site_email'), setting('site_name'));
        $this->email->to(strtolower($email));
        $this->email->subject($mail_template['subject'], $mail_data);
        $this->email->message($mail_template['body'], $mail_data);

        if (!$this->email->send()) {
            log_message('error', $this->email->print_debugger(['headers']));
            $notify = '0';
        }
        else {
            $notify = '1';
        }

        return $notify;
    }

    /**
     * Delete a single or multiple order by order_id, with relationships
     *
     * @param int $order_id
     *
     * @return int  The number of deleted rows
     */
    public function deleteOrder($order_id)
    {
        if (is_numeric($order_id)) $order_id = [$order_id];

        if (!empty($order_id) AND ctype_digit(implode('', $order_id))) {
            return $this->newQuery()->whereIn('order_id', $order_id)->delete();
        }
    }
}