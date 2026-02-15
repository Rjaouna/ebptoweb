<?php

namespace App\Service;

use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\User;
use App\Repository\CartItemRepository;
use App\Repository\CartRepository;
use Doctrine\ORM\EntityManagerInterface;

class CartService
{
    public function __construct(
        private EntityManagerInterface $em,
        private CartRepository $cartRepo,
        private CartItemRepository $itemRepo
    ) {}

    public function getOrCreate(User $user): Cart
    {
        $cart = $this->cartRepo->findOneByUser($user);
        if ($cart) return $cart;

        $cart = new Cart();
        $cart->setUser($user);

        $this->em->persist($cart);
        $this->em->flush();

        return $cart;
    }

    /**
     * Ajoute une quantité au panier (uniquement sur ligne in_cart).
     * Si l'article existe en in_order, on crée une nouvelle ligne in_cart.
     */
    public function add(User $user, string $uid, int $qty): void
    {
        $qty = max(1, min(999, $qty));
        $cart = $this->getOrCreate($user);

        // ✅ on cherche UNIQUEMENT une ligne in_cart
        $item = $this->itemRepo->findOneByCartUidAndStatus($cart, $uid, CartItem::STATUS_IN_CART);

        if (!$item) {
            $item = new CartItem($uid, $qty); // status in_cart via lifecycle / default
            $item->setStatus(CartItem::STATUS_IN_CART);
            $cart->addItem($item);
            $this->em->persist($item);
        } else {
            $item->setQuantity($item->getQuantity() + $qty);
        }

        $cart->touch();
        $this->em->flush();
    }

    /**
     * Définit la quantité (sur ligne in_cart).
     * qty=0 => supprime la ligne in_cart uniquement.
     */
    public function setQty(User $user, string $uid, int $qty): void
    {
        $qty = max(0, min(999, $qty));
        $cart = $this->getOrCreate($user);

        $item = $this->itemRepo->findOneByCartUidAndStatus($cart, $uid, CartItem::STATUS_IN_CART);

        if ($qty === 0) {
            if ($item) {
                $cart->removeItem($item);
                $this->em->remove($item);
                $cart->touch();
                $this->em->flush();
            }
            return;
        }

        if (!$item) {
            // ✅ si pas de ligne in_cart, on en crée une (même si une in_order existe)
            $item = new CartItem($uid, $qty);
            $item->setStatus(CartItem::STATUS_IN_CART);
            $cart->addItem($item);
            $this->em->persist($item);
        } else {
            $item->setQuantity($qty);
        }

        $cart->touch();
        $this->em->flush();
    }

    /** Supprime uniquement la ligne in_cart pour ce uid */
    public function remove(User $user, string $uid): void
    {
        $cart = $this->getOrCreate($user);

        $item = $this->itemRepo->findOneByCartUidAndStatus($cart, $uid, CartItem::STATUS_IN_CART);
        if (!$item) return;

        $cart->removeItem($item);
        $this->em->remove($item);
        $cart->touch();
        $this->em->flush();
    }

    /** Vide uniquement les lignes in_cart */
    public function clearInCart(User $user): void
    {
        $cart = $this->getOrCreate($user);

        foreach ($cart->getItems() as $item) {
            if ($item->getStatus() === CartItem::STATUS_IN_CART) {
                $this->em->remove($item);
            }
        }

        $cart->touch();
        $this->em->flush();
    }

    /** (Optionnel) Vide TOUTES les lignes peu importe le status */
    public function clearAll(User $user): void
    {
        $cart = $this->getOrCreate($user);

        foreach ($cart->getItems() as $item) {
            $this->em->remove($item);
        }

        $cart->touch();
        $this->em->flush();
    }

    /** @return array<string,int> uid => qty (uniquement in_cart) */
    public function asUidQtyMap(User $user): array
    {
        $cart = $this->cartRepo->findOneByUser($user);
        if (!$cart) return [];

        $map = [];
        foreach ($cart->getItems() as $it) {
            if ($it->getStatus() !== CartItem::STATUS_IN_CART) continue;
            $map[$it->getUid()] = $it->getQuantity();
        }

        return $map;
    }

    /** Total quantité (uniquement in_cart) */
    public function totalQty(User $user): int
    {
        $cart = $this->cartRepo->findOneByUser($user);
        if (!$cart) return 0;

        $n = 0;
        foreach ($cart->getItems() as $it) {
            if ($it->getStatus() !== CartItem::STATUS_IN_CART) continue;
            $n += $it->getQuantity();
        }
        return $n;
    }

    /** @return CartItem[] lignes in_cart */
    public function getItemsInCart(User $user): array
    {
        $cart = $this->cartRepo->findOneByUser($user);
        if (!$cart) return [];

        $out = [];
        foreach ($cart->getItems() as $it) {
            if ($it->getStatus() === CartItem::STATUS_IN_CART) {
                $out[] = $it;
            }
        }
        return $out;
    }
}
