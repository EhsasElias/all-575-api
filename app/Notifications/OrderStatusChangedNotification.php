<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OrderStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private Order $order,
        private ?string $note = null
    ) {
    }

    public function via(object $notifiable): array
    {
        return [
            'database',
        ];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order_status',

            'order_id' =>
                $this->order->id,

            'order_number' =>
                $this->order->order_number,

            'status' =>
                $this->order->status,

            'title_ar' =>
                'تحديث حالة الطلب',

            'title_en' =>
                'Order status updated',

            'message_ar' =>
                $this->arabicMessage(),

            'message_en' =>
                $this->englishMessage(),

            'note' =>
                $this->note,
        ];
    }

    private function arabicMessage(): string
    {
        return match ($this->order->status) {
            'confirmed' =>
                'تم تأكيد طلبك.',

            'preparing' =>
                'طلبك قيد التجهيز.',

            'out_for_delivery' =>
                'طلبك في طريقه إليك.',

            'delivered' =>
                'تم توصيل طلبك بنجاح.',

            'cancelled' =>
                'تم إلغاء الطلب.',

            default =>
                'تم تحديث حالة طلبك.',
        };
    }

    private function englishMessage(): string
    {
        return match ($this->order->status) {
            'confirmed' =>
                'Your order has been confirmed.',

            'preparing' =>
                'Your order is being prepared.',

            'out_for_delivery' =>
                'Your order is out for delivery.',

            'delivered' =>
                'Your order has been delivered.',

            'cancelled' =>
                'Your order has been cancelled.',

            default =>
                'Your order status has been updated.',
        };
    }
}
