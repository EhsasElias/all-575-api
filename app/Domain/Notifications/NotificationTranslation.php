<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Render known system-generated notices in the requested API language.
 *
 * Stored notifications remain unchanged: the same notice can be read in a different
 * language after a user changes their preference. Unknown/custom notices are
 * returned verbatim, rather than guessing a translation of user-provided text.
 */
final class NotificationTranslation
{
    /**
     * @return array{title: string, message: string}
     */
    public static function render(string $type, string $title, string $message, string $locale): array
    {
        if (! in_array($locale, ['en', 'fr'], true)) {
            return compact('title', 'message');
        }

        $static = [
            'order_placed' => [
                'en' => ['We received your order', 'Your order was placed successfully. You can track it from My Orders.'],
                'fr' => ['Nous avons reçu votre commande', 'Votre commande a bien été enregistrée. Suivez-la dans Mes commandes.'],
            ],
        ];

        if (isset($static[$type][$locale])) {
            [$translatedTitle, $translatedMessage] = $static[$type][$locale];

            return ['title' => $translatedTitle, 'message' => $translatedMessage];
        }

        $templates = [
            'order_confirmed' => [
                'en' => ['Your order is confirmed', 'Order %s has been confirmed. You can track it from My Orders.'],
                'fr' => ['Votre commande est confirmée', 'La commande %s est confirmée. Vous pouvez la suivre dans Mes commandes.'],
            ],
            'order_delivered' => [
                'en' => ['Order delivered', 'Order %s has been delivered and verified.'],
                'fr' => ['Commande livrée', 'La livraison de la commande %s a été vérifiée.'],
            ],
            'courier_delivered' => [
                'en' => ['Order delivered', 'Delivery of order %s was verified.'],
                'fr' => ['Commande livrée', 'La livraison de la commande %s a été vérifiée.'],
            ],
            'courier_picked_up' => [
                'en' => ['Parcel picked up', 'Order %s was collected from the seller and is on the way.'],
                'fr' => ['Colis récupéré', 'La commande %s a été récupérée auprès du vendeur et est en route.'],
            ],
            'courier_delivery_failed' => [
                'en' => ['Delivery attempt failed', 'Delivery of order %s could not be completed. An incident was filed.'],
                'fr' => ['Échec de livraison', 'La livraison de la commande %s a échoué. Un incident a été signalé.'],
            ],
            'seller_new_order' => [
                'en' => ['New store order', 'Order %s is confirmed and needs preparation.'],
                'fr' => ['Nouvelle commande du magasin', 'La commande %s est confirmée et doit être préparée.'],
            ],
            'admin_new_order' => [
                'en' => ['New order to monitor', 'Order %s has been confirmed for a store you manage.'],
                'fr' => ['Nouvelle commande à suivre', 'La commande %s est confirmée pour un magasin que vous gérez.'],
            ],
            'super_admin_new_order' => [
                'en' => ['New platform order', 'Order %s has been confirmed on ALL 5.75.'],
                'fr' => ['Nouvelle commande sur la plateforme', 'La commande %s est confirmée sur ALL 5.75.'],
            ],
        ];

        if (! isset($templates[$type][$locale])) {
            return compact('title', 'message');
        }

        // These system-generated messages include the order number after "الطلب".
        // Do not invent numbers or translate arbitrary messages if the format differs.
        if (preg_match('/الطلب[[:space:]]+([^[:space:]]+)/u', $message, $matches) !== 1) {
            return compact('title', 'message');
        }

        [$translatedTitle, $translatedTemplate] = $templates[$type][$locale];

        return [
            'title' => $translatedTitle,
            'message' => sprintf($translatedTemplate, $matches[1]),
        ];
    }
}
