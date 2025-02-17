<?php
/*************************************************************************************/
/*      This file is part of the module EasyProductManager.                          */
/*                                                                                   */
/*      Copyright (c) Gilles Bourgeat                                                */
/*      email : gilles.bourgeat@gmail.com                                            */
/*                                                                                   */
/*      This module is not open source                                               /*
/*      please contact gilles.bourgeat@gmail.com for a license                       */
/*                                                                                   */
/*                                                                                   */
/*************************************************************************************/

namespace EasyOrderManager\Controller;

use EasyOrderManager\EasyOrderManager;
use EasyOrderManager\Event\BeforeFilterEvent;
use EasyOrderManager\Event\TemplateFieldEvent;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Join;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Controller\Admin\ProductController;
use Thelia\Core\HttpFoundation\JsonResponse;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Thelia;
use Thelia\Core\Translation\Translator;
use Thelia\Model\Map\CustomerTableMap;
use Thelia\Model\Map\OrderAddressTableMap;
use Thelia\Model\Map\OrderTableMap;
use Thelia\Model\Order;
use Thelia\Model\OrderQuery;
use Thelia\Tools\MoneyFormat;
use Thelia\Tools\URL;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/easy-order-manager", name="admin_easy_order_manager")
 */
class BackController extends ProductController
{
    protected const ORDER_INVOICE_ADDRESS_JOIN = 'orderInvoiceAddressJoin';

    /**
     * @Route("/list", name="_list", methods={"GET","POST"})
     */
    public function listAction(RequestStack $requestStack, EventDispatcherInterface $dispatcher): JsonResponse
    {
        if (null !== $response = $this->checkAuth(AdminResources::ORDER, [], AccessManager::UPDATE)) {
            return $response;
        }
        $request = $requestStack->getCurrentRequest();
        if ($request->isXmlHttpRequest()) {

            $locale = $request->getSession()->getLang()->getLocale();

            // Use Customer for email column in applySearchCustomer
            $query = OrderQuery::create()
                ->useCustomerQuery()
                ->endUse();

            $this->applyOrder($request, $query);

            $queryCount = clone $query;

            $beforeFilterEvent = new BeforeFilterEvent($request, $query);
            $dispatcher->dispatch($beforeFilterEvent, BeforeFilterEvent::ORDER_MANAGER_BEFORE_FILTER);

            $this->filterByStatus($request, $query);
            $this->filterByPaymentModule($request, $query);
            $this->filterByCreatedAt($request, $query);
            $this->filterByInvoiceDate($request, $query);

            $this->applySearchOrder($request, $query);
            $this->applySearchCompany($request, $query);
            $this->applySearchCustomer($request, $query);

            $querySearchCount = clone $query;

            $query->offset($this->getOffset($request));

            $orders = $query->limit(25)->find();

            $json = [
                "draw"=> $this->getDraw($request),
                "recordsTotal"=> $queryCount->count(),
                "recordsFiltered"=> $querySearchCount->count(),
                "data" => [],
                "orders" => count($orders->getData()),
            ];

            $moneyFormat = MoneyFormat::getInstance($request);

            /** @var Order $order */
            foreach ($orders as $order) {
                $amount = $moneyFormat->formatByCurrency(
                    $order->getTotalAmount(),
                    2,
                    '.',
                    ' ',
                    $order->getCurrencyId()
                );
                // for each defineColumnsDefinition

                $updateUrl = URL::getInstance()->absoluteUrl('admin/order/update/'.$order->getId());

                $json['data'][] = [
                    [
                        'id' => $order->getId(),
                        'href' => $updateUrl
                    ],
                    [
                        'ref' => $order->getRef(),
                        'href' => $updateUrl
                    ],
                    $order->getCreatedAt('d/m/y H:i:s'),
                    $order->getInvoiceDate('d/m/y H:i:s'),
                    $order->getOrderAddressRelatedByInvoiceOrderAddressId()->getCompany(),
                    [
                        'href' => URL::getInstance()->absoluteUrl('admin/customer/update?customer_id='.$order->getCustomerId()),
                        'name' => $order->getOrderAddressRelatedByInvoiceOrderAddressId()->getFirstname().' '.$order->getOrderAddressRelatedByInvoiceOrderAddressId()->getLastname(),
                    ],
                    $amount,
                    [
                        'name' => $order->getOrderStatus()->setLocale($locale)->getTitle(),
                        'color' => $order->getOrderStatus()->getColor()
                    ],
                    [
                        'order_id' => $order->getId(),
                        'hrefUpdate' => $updateUrl,
                        'hrefPrint' => URL::getInstance()->absoluteUrl('admin/order/pdf/invoice/'.$order->getId().'/1'),
                        'isCancelled' => $order->isCancelled()
                    ]
                ];
            }

            return new JsonResponse($json);
        }

        $templateFieldEvent = new TemplateFieldEvent();
        $dispatcher->dispatch($templateFieldEvent, TemplateFieldEvent::ORDER_MANAGER_TEMPLATE_FIELD);

        return $this->render('EasyOrderManager/list', [
            'columnsDefinition' => $this->defineColumnsDefinition(),
            'theliaVersion' => Thelia::THELIA_VERSION,
            'moduleVersion' => EasyOrderManager::MODULE_VERSION,
            'moduleName' => EasyOrderManager::MODULE_NAME,
            'template_fields' => $templateFieldEvent->getTemplateFields()
        ]);
    }

    /**
     * @param Request $request
     * @return string
     */
    protected function getOrderColumnName(Request $request): string
    {
        $columnDefinition = $this->defineColumnsDefinition(true)[
        (int) $request->get('order')[0]['column']
        ];

        return $columnDefinition['orm'];
    }

    /**
     * @param Request $request
     * @param OrderQuery $query
     * @return void
     */
    protected function applyOrder(Request $request, OrderQuery $query): void
    {
        $query->orderBy(
            $this->getOrderColumnName($request),
            $this->getOrderDir($request)
        );
    }

    /**
     * @param Request $request
     * @return string
     */
    protected function getOrderDir(Request $request): string
    {
        return (string) $request->get('order')[0]['dir'] === 'asc' ? Criteria::ASC : Criteria::DESC;
    }

    /**
     * @param Request $request
     * @return int
     */
    protected function getLength(Request $request): int
    {
        return (int) $request->get('length');
    }

    /**
     * @param Request $request
     * @return int
     */
    protected function getOffset(Request $request): int
    {
        return (int) $request->get('start');
    }


    /**
     * @param Request $request
     * @return int
     */
    protected function getDraw(Request $request): int
    {
        return (int) $request->get('draw');
    }

    /**
     * @param bool $withPrivateData
     * @return array
     */
    protected function defineColumnsDefinition($withPrivateData = false): array
    {
        $i = -1;

        $definitions = [
            [
                'name' => 'id',
                'targets' => ++$i,
                'orm' => OrderTableMap::COL_ID,
                'title' => Translator::getInstance()->trans('Id', [], EasyOrderManager::DOMAIN_NAME),
            ],
            [
                'name' => 'ref',
                'targets' => ++$i,
                'orm' => OrderTableMap::COL_REF,
                'title' => 'Référence',
            ],
            [
                'name' => 'create_date',
                'targets' => ++$i,
                'orm' => OrderTableMap::COL_CREATED_AT,
                'title' => 'Date de création',
            ],
            [
                'name' => 'invoice_date',
                'targets' => ++$i,
                'orm' => OrderTableMap::COL_INVOICE_DATE,
                'title' => 'Date de facturation',
            ],
            [
                'name' => 'company',
                'targets' => ++$i,
                'title' => 'Entreprise',
                'orderable' => false,
            ],
            [
                'name' => 'client',
                'targets' => ++$i,
                'title' => 'Nom du client',
                'orderable' => false,
            ],
            [
                'name' => 'amount',
                'targets' => ++$i,
                'title' => 'Montant',
                'orderable' => false,
            ],
            [
                'name' => 'status',
                'targets' => ++$i,
                'title' => 'Etat',
                'orderable' => false,
            ],
            [
                'name' => 'action',
                'targets' => ++$i,
                'title' => 'Action',
                'orderable' => false,
            ]
        ];

        if (!$withPrivateData) {
            foreach ($definitions as &$definition) {
                unset($definition['orm']);
            }
        }

        return $definitions;
    }

    /**
     * @param Request $request
     * @param OrderQuery $query
     * @return void
     */
    protected function filterByStatus(Request $request, OrderQuery $query): void
    {
        if (0 !== $statusId = (int) $request->get('filter')['status']) {
            $query->filterByStatusId($statusId);
        }
    }

    /**
     * @param Request $request
     * @param OrderQuery $query
     * @return void
     */
    protected function filterByPaymentModule(Request $request, OrderQuery $query): void
    {
        if (0 !== $paymentModuleId = (int) $request->get('filter')['paymentModuleId']) {
            $query->filterByPaymentModuleId($paymentModuleId);
        }
    }

    /**
     * @param Request $request
     * @param OrderQuery $query
     * @return void
     */
    protected function filterByCreatedAt(Request $request, OrderQuery $query): void
    {
        if ('' !== $createdAtFrom = $request->get('filter')['createdAtFrom']) {
            $query->filterByInvoiceDate(sprintf("%s 00:00:00", $createdAtFrom), Criteria::GREATER_EQUAL);
        }
        if ('' !== $createdAtTo = $request->get('filter')['createdAtTo']) {
            $query->filterByInvoiceDate(sprintf("%s 23:59:59", $createdAtTo), Criteria::LESS_EQUAL);
        }
    }

    /**
     * @param Request $request
     * @param OrderQuery $query
     * @return void
     */
    protected function filterByInvoiceDate(Request $request, OrderQuery $query): void
    {
        if ('' !== $invoiceDateFrom = $request->get('filter')['invoiceDateFrom']) {
            $query->filterByCreatedAt(sprintf("%s 00:00:00", $invoiceDateFrom), Criteria::GREATER_EQUAL);
        }
        if ('' !== $invoiceDateTo = $request->get('filter')['invoiceDateTo']) {
            $query->filterByCreatedAt(sprintf("%s 23:59:59", $invoiceDateTo), Criteria::LESS_EQUAL);
        }
    }

    /**
     * @param Request $request
     * @param OrderQuery $query
     * @return void
     */
    protected function applySearchOrder(Request $request, OrderQuery $query): void
    {
        $value = $this->getSearchValue($request, 'searchOrder');

        if (strlen($value) > 2) {
            $query->where(OrderTableMap::COL_REF . ' LIKE ?', '%' . $value . '%', \PDO::PARAM_STR);
            $query->_or()->where(OrderTableMap::COL_ID . ' LIKE ?', '%' . $value . '%', \PDO::PARAM_STR);
            $query->_or()->where(OrderTableMap::COL_INVOICE_REF . ' LIKE ?', '%' . $value . '%', \PDO::PARAM_STR);
            $query->_or()->where(OrderTableMap::COL_DELIVERY_REF . ' LIKE ?', '%' . $value . '%', \PDO::PARAM_STR);
        }
    }

    /**
     * @param Request $request
     * @param OrderQuery $query
     * @return void
     */
    protected function applySearchCompany(Request $request, OrderQuery $query): void
    {
        $value = $this->getSearchValue($request, 'searchCompany');

        if (strlen($value) > 2) {
            if (!$query->hasJoin($this::ORDER_INVOICE_ADDRESS_JOIN)) {
                $orderInvoiceAddressJoin = new Join(
                    OrderTableMap::COL_INVOICE_ORDER_ADDRESS_ID,
                    OrderAddressTableMap::COL_ID,
                    Criteria::INNER_JOIN
                );

                $query->addJoinObject($orderInvoiceAddressJoin, $this::ORDER_INVOICE_ADDRESS_JOIN);
            }

            $query->addJoinCondition(
                $this::ORDER_INVOICE_ADDRESS_JOIN,
                OrderAddressTableMap::COL_COMPANY . " LIKE '%" . $value . "%'"
            );
        }
    }

    /**
     * @param Request $request
     * @param OrderQuery $query
     * @return void
     */
    protected function applySearchCustomer(Request $request, OrderQuery $query): void
    {
        $value = $this->getSearchValue($request, 'searchCustomer');
        $value = str_replace(array('+33', ' '), array('0', ''), $value);

        if (strlen($value) > 2) {
            if (!$query->hasJoin($this::ORDER_INVOICE_ADDRESS_JOIN)) {
                $orderInvoiceAddressJoin = new Join(
                    OrderTableMap::COL_INVOICE_ORDER_ADDRESS_ID,
                    OrderAddressTableMap::COL_ID,
                    Criteria::INNER_JOIN
                );

                $query->addJoinObject($orderInvoiceAddressJoin, $this::ORDER_INVOICE_ADDRESS_JOIN);
            }

            $query->addJoinCondition(
                $this::ORDER_INVOICE_ADDRESS_JOIN,
                '('.OrderAddressTableMap::COL_FIRSTNAME." LIKE '%".$value."%' OR ".
                OrderAddressTableMap::COL_LASTNAME." LIKE '%".$value."%' OR ".
                OrderAddressTableMap::COL_CELLPHONE." LIKE '%".$value."%' OR ".
                OrderAddressTableMap::COL_LASTNAME." LIKE '%".$value."%' OR ".
                CustomerTableMap::COL_EMAIL." LIKE '%".$value."%')"
            );

            $query->groupById();
        }
    }

    /**
     * @param Request $request
     * @param $searchKey
     * @return string
     */
    protected function getSearchValue(Request $request, $searchKey): string
    {
        return (string) $request->get($searchKey)['value'];
    }
}