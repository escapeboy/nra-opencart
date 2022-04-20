<?php
declare(strict_types=1);

/**
 * @property ModelExtensionModuleNra $model_extension_module_nra
 */
class ControllerExtensionModuleNra extends Controller
{
    private $error = array();

    public function index()
    {
        $this->load->language('extension/module/nra');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('extension/module/nra');
        $this->load->model('setting/setting');

        if ($this->request->server['REQUEST_METHOD'] === 'POST') {
            $this->model_setting_setting->editSetting('nra_audit_', $this->request->post);

            $this->response->redirect($this->url->link('extension/module/nra',
                'user_token=' . $this->session->data['user_token'], true));
        }

        $data = [];
        $data['selected_month'] = isset($this->request->get['filter_month']) ? (int)$this->request->get['filter_month'] : (int)date('n');
        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');
        $data['months'] = [];
        $data['settings'] = $this->model_setting_setting->getSetting('nra_audit_');
        $data['heading_title'] = 'Експорт на поръчки за генериране на XML към НАП';
        for ($i = 1; $i <= date('n'); $i++) {
            $data['months'][$i] = $this->getMonths()[$i];
        }
        $data['payment_methods'] = array_map(function ($payment) {
            $this->load->language('extension/payment/' . $payment['code']);

            return [
                'code' => $payment['code'],
                'title' => $this->language->get('heading_title'),
            ];
        }, $this->model_extension_module_nra->getPaymentMethods());
        $data['statuses'] = $this->model_extension_module_nra->getOrderStatuses();
        $data['refundMethods'] = [
            ['value' => 'Audit\\ReturnMethods\\IBAN', 'title' => 'по платежна сметка'],
            ['value' => 'Audit\\ReturnMethods\\Card', 'title' => 'по карта'],
            ['value' => 'Audit\\ReturnMethods\\Cash', 'title' => 'в брой'],
            ['value' => 'Audit\\ReturnMethods\\Other', 'title' => 'друг'],
        ];

        if (isset($this->request->get['download'])) {
            $filter_date_added = (new DateTime())->setDate((int)date('Y'), $data['selected_month'] ?: date('n'),
                1)->setTime(0, 0);
            $filter_end_date = (clone $filter_date_added)->add(new DateInterval('P1M'));

            $results = $this->model_extension_module_nra->getOrders($filter_date_added->format('Y-m-d H:i'),
                $filter_end_date->format('Y-m-d H:i'));
            $orders = [];
            $refunded = [];

            foreach ($results as $result) {
                if ($result['order_status_id'] === $data['settings']['nra_audit_completed_status_id']) {
                    $orders[] = [
                        'documentNumber' => sprintf('%s%s', $result['invoice_prefix'], $result['invoice_no']),
                        'documentDate' => $result['date_added'],
                        'orderDate' => $result['date_added'],
                        'items' => $this->getOrderItems($result['order_id'], $data['settings']),
                        'orderUniqueNumber' => $result['order_id'],
                        'paymentProcessorIdentifier' => $data['settings']['nra_audit_paymentProcessorIdentifier'],
                        'paymentType' => $this->getPaymentType($result, $data['settings']),
                        'totalDiscount' => 0,
                        'transactionNumber' => null,
                        'virtualPosNumber' => $data['settings']['nra_audit_virtualPosNumber'],
                    ];
                } elseif ($result['order_status_id'] === $data['settings']['nra_audit_refunded_status_id']) {
                    $refunded[] = [
                        'orderAmount' => $result['total'],
                        'orderDate' => $result['date_added'],
                        'orderNumber' => $result['order_id'],
                        'returnMethod' => $data['settings']['nra_audit_default_refund_method'],
                    ];
                }
            }

            $jsonResult = [
                'domain' => $data['settings']['nra_audit_domain'],
                'eik' => $data['settings']['nra_audit_eik'],
                'isMarketplace' => (bool)$data['settings']['nra_audit_isMarketplace'],
                'month' => $data['selected_month'],
                'orders' => $orders,
                'returned' => $refunded,
                'shopUniqueNumber' => $data['settings']['nra_audit_shopUniqueNumber'],
                'year' => date('Y')
            ];

            $this->response->addHeader('Content-type: application/json');
            $this->response->addHeader('Content-Type: application/octet-stream');
            $this->response->addHeader('Content-Disposition: attachment; filename=nra.json');
            $this->response->setOutput(json_encode($jsonResult));
            return;
        }

        $this->response->setOutput($this->load->view('extension/module/nra', $data));
    }

    private function getMonths()
    {
        return [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December',
        ];
    }

    private function getPaymentType(array $result, array $settings)
    {
        $paymentCode = $result['payment_code'];
        if (in_array($paymentCode, $settings['nra_audit_withoutPostPayment'], true)) {
            return 'Audit\\PaymentTypes\\WithoutPostPayment';
        }

        if (in_array($paymentCode, $settings['nra_audit_WithPostPayment'], true)) {
            return 'Audit\\PaymentTypes\\WithPostPayment';
        }
        if (in_array($paymentCode, $settings['nra_audit_PaymentService'], true)) {
            return 'Audit\\PaymentTypes\\PaymentService';
        }
        if (in_array($paymentCode, $settings['nra_audit_VirtualPOSTerminal'], true)) {
            return 'Audit\\PaymentTypes\\VirtualPOSTerminal';
        }
        if (in_array($paymentCode, $settings['nra_audit_Other'], true)) {
            return 'Audit\\PaymentTypes\\Other';
        }

        return null;
    }

    private function getOrderItems($order_id, $settings)
    {
        $orderItems = [];
        $items = $this->model_extension_module_nra->getOrderItems(
            $order_id
        );

        foreach ($items as $item) {
            $orderItems[] = [
                'name' => $item['name'],
                'price' => $this->calculateItemPrice($item, $settings),
                'quantity' => $item['quantity'],
                'vatRate' => $settings['nra_audit_final_prices_with_tax'] ? $item['tax'] : $settings['nra_audit_global_tax']
            ];
        }

        return $orderItems;
    }

    private function calculateItemPrice($item, $settings)
    {
        $itemPrice = $item['price'];
        if ($settings['nra_audit_final_prices_with_tax']) {
            return $itemPrice;
        }

        return round($itemPrice / (1 + ($settings['nra_audit_global_tax'] / 100)), 2);
    }
}
