<?php
/**
 * Roadmap Message Builder
 * 
 * Builds roadmap Flex messages showing order timeline
 * Based on user's timeline design with status indicators
 */

class RoadmapMessageBuilder
{
    private $eventLabels = [
        'order.validated' => ['icon' => '✅', 'label' => 'ยืนยันออเดอร์', 'color' => '#06C755'],
        'order.picker_assigned' => ['icon' => '👤', 'label' => 'เตรียมจัดสินค้า', 'color' => '#569CD6'],
        'order.picking' => ['icon' => '📦', 'label' => 'กำลังจัดสินค้า', 'color' => '#569CD6'],
        'order.picked' => ['icon' => '✅', 'label' => 'จัดเสร็จแล้ว', 'color' => '#06C755'],
        'order.packing' => ['icon' => '📦', 'label' => 'กำลังแพ็ค', 'color' => '#569CD6'],
        'order.packed' => ['icon' => '✅', 'label' => 'แพ็คเสร็จ', 'color' => '#06C755'],
        'order.reserved' => ['icon' => '🔒', 'label' => 'จองสินค้า', 'color' => '#569CD6'],
        'order.awaiting_payment' => ['icon' => '💰', 'label' => 'รอชำระเงิน', 'color' => '#FFA500'],
        'order.paid' => ['icon' => '💳', 'label' => 'ชำระเงินแล้ว', 'color' => '#06C755'],
        'order.to_delivery' => ['icon' => '🚚', 'label' => 'เตรียมส่ง', 'color' => '#569CD6'],
        'order.in_delivery' => ['icon' => '🚚', 'label' => 'กำลังจัดส่ง', 'color' => '#569CD6'],
        'order.delivered' => ['icon' => '✅', 'label' => 'จัดส่งสำเร็จ', 'color' => '#06C755'],
    ];
    
    /**
     * Build roadmap Flex message
     */
    public function buildRoadmapFlex($events, $orderData)
    {
        $orderRef = $orderData['order_ref'] ?? 'SO-XXX';
        $eventCount = count($events);
        
        $timelineItems = $this->buildTimelineItems($events);
        $currentState = $this->getCurrentState($events);
        
        return [
            'type' => 'bubble',
            'size' => 'mega',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => '🕐 Timeline: ' . $orderRef,
                        'weight' => 'bold',
                        'size' => 'lg',
                        'color' => '#ffffff'
                    ]
                ],
                'backgroundColor' => '#1E88E5',
                'paddingAll' => '15px'
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => array_merge(
                    [
                        [
                            'type' => 'text',
                            'text' => "อัปเดต {$eventCount} สถานะ",
                            'size' => 'sm',
                            'color' => '#999999',
                            'margin' => 'none'
                        ],
                        [
                            'type' => 'separator',
                            'margin' => 'lg'
                        ]
                    ],
                    $timelineItems,
                    [
                        [
                            'type' => 'separator',
                            'margin' => 'lg'
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'contents' => [
                                [
                                    'type' => 'text',
                                    'text' => 'สถานะปัจจุบัน: ' . $currentState['label'],
                                    'weight' => 'bold',
                                    'size' => 'md',
                                    'color' => $currentState['color'],
                                    'wrap' => true
                                ]
                            ],
                            'margin' => 'md',
                            'paddingAll' => '12px',
                            'backgroundColor' => '#F5F5F5',
                            'cornerRadius' => '8px'
                        ]
                    ]
                ),
                'paddingAll' => '15px'
            ]
        ];
    }
    
    /**
     * Build timeline items from events
     */
    private function buildTimelineItems($events)
    {
        $items = [];
        
        foreach ($events as $index => $event) {
            $eventType = $event['event_type'];
            $timestamp = $event['timestamp'];
            $meta = $this->eventLabels[$eventType] ?? ['icon' => '📌', 'label' => $eventType, 'color' => '#999999'];
            
            $isCompleted = $this->isCompletedEvent($eventType);
            $indicator = $isCompleted ? '●' : '○';
            $indicatorColor = $isCompleted ? '#06C755' : '#CCCCCC';
            
            $formattedTime = $this->formatEventTime($timestamp);
            
            $items[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => $indicator,
                                'size' => 'xl',
                                'color' => $indicatorColor,
                                'align' => 'center'
                            ]
                        ],
                        'flex' => 0,
                        'width' => '30px'
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'vertical',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => $meta['icon'] . ' ' . $meta['label'],
                                'weight' => 'bold',
                                'size' => 'sm',
                                'color' => '#333333',
                                'wrap' => true
                            ],
                            [
                                'type' => 'text',
                                'text' => $formattedTime,
                                'size' => 'xs',
                                'color' => '#999999',
                                'margin' => 'xs'
                            ],
                            [
                                'type' => 'text',
                                'text' => $eventType,
                                'size' => 'xxs',
                                'color' => '#CCCCCC',
                                'margin' => 'xs'
                            ]
                        ],
                        'flex' => 1
                    ]
                ],
                'margin' => 'md',
                'spacing' => 'sm'
            ];
            
            if ($index < count($events) - 1) {
                $items[] = [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'contents' => [
                                [
                                    'type' => 'filler'
                                ]
                            ],
                            'flex' => 0,
                            'width' => '30px'
                        ],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'contents' => [
                                [
                                    'type' => 'separator',
                                    'color' => '#E0E0E0'
                                ]
                            ],
                            'flex' => 1
                        ]
                    ],
                    'height' => '8px',
                    'margin' => 'xs'
                ];
            }
        }
        
        return $items;
    }
    
    /**
     * Check if event represents completion
     */
    private function isCompletedEvent($eventType)
    {
        $completedEvents = [
            'order.validated',
            'order.picked',
            'order.packed',
            'order.paid',
            'order.delivered'
        ];
        
        return in_array($eventType, $completedEvents);
    }
    
    /**
     * Get current state from last event
     */
    private function getCurrentState($events)
    {
        if (empty($events)) {
            return ['label' => 'ไม่ทราบสถานะ', 'color' => '#999999'];
        }
        
        $lastEvent = end($events);
        $eventType = $lastEvent['event_type'];
        
        $stateMap = [
            'order.validated' => ['label' => 'ยืนยันออเดอร์แล้ว', 'color' => '#06C755'],
            'order.picker_assigned' => ['label' => 'กำลังเตรียมจัดสินค้า', 'color' => '#569CD6'],
            'order.picking' => ['label' => 'กำลังจัดสินค้า', 'color' => '#569CD6'],
            'order.picked' => ['label' => 'จัดสินค้าเสร็จแล้ว', 'color' => '#06C755'],
            'order.packing' => ['label' => 'กำลังแพ็คสินค้า', 'color' => '#569CD6'],
            'order.packed' => ['label' => 'พร้อมจัดส่ง', 'color' => '#06C755'],
            'order.to_delivery' => ['label' => 'เตรียมจัดส่ง', 'color' => '#569CD6'],
            'order.in_delivery' => ['label' => 'กำลังจัดส่ง', 'color' => '#569CD6'],
            'order.delivered' => ['label' => 'จัดส่งสำเร็จ', 'color' => '#06C755'],
        ];
        
        return $stateMap[$eventType] ?? ['label' => $eventType, 'color' => '#999999'];
    }
    
    /**
     * Format event timestamp
     */
    private function formatEventTime($timestamp)
    {
        try {
            $dt = new DateTime($timestamp);
            return $dt->format('d/m/Y H:i:s');
        } catch (Exception $e) {
            return $timestamp;
        }
    }
    
    /**
     * Get milestone icon
     */
    private function getMilestoneIcon($eventType)
    {
        $meta = $this->eventLabels[$eventType] ?? null;
        return $meta ? $meta['icon'] : '📌';
    }
}
