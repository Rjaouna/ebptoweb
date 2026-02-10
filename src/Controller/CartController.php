<?php

namespace App\Controller;

use App\Service\CartService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CartController extends AbstractController
{
	#[Route('/cart', name: 'cart_index', methods: ['GET'])]
	public function index(CartService $cart): Response
	{
		$this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

		/** @var \App\Entity\User $user */
		$user = $this->getUser();

		$cartMap = $cart->asUidQtyMap($user);

		return $this->render('cart/index.html.twig', [
			'cartMap' => $cartMap,
			'cartQty' => array_sum($cartMap),
		]);
	}

	#[Route('/cart/state', name: 'cart_state', methods: ['GET'])]
	public function state(CartService $cart): JsonResponse
	{
		$this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

		/** @var \App\Entity\User $user */
		$user = $this->getUser();

		$map = $cart->asUidQtyMap($user);

		return $this->json([
			'ok' => true,
			'totalQty' => array_sum($map),
			'cart' => $map,
		]);
	}

	#[Route('/cart/add', name: 'cart_add', methods: ['POST'])]
	public function add(Request $request, CartService $cart): JsonResponse
	{
		$this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

		$uid = trim((string)$request->request->get('id', ''));
		$qty = (int)$request->request->get('qty', 1);
		$qty = max(1, min(999, $qty));

		if ($uid === '') {
			return $this->json(['ok' => false, 'message' => 'Article invalide.'], 400);
		}

		/** @var \App\Entity\User $user */
		$user = $this->getUser();

		$cart->add($user, $uid, $qty);

		$map = $cart->asUidQtyMap($user);

		return $this->json([
			'ok' => true,
			'totalQty' => array_sum($map),
			'cart' => $map,
		]);
	}

	#[Route('/cart/set', name: 'cart_set', methods: ['POST'])]
	public function set(Request $request, CartService $cart): JsonResponse
	{
		$this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

		$uid = trim((string)$request->request->get('id', ''));
		$qty = (int)$request->request->get('qty', 1);
		$qty = max(0, min(999, $qty));

		if ($uid === '') {
			return $this->json(['ok' => false, 'message' => 'Article invalide.'], 400);
		}

		/** @var \App\Entity\User $user */
		$user = $this->getUser();

		$cart->setQty($user, $uid, $qty);

		$map = $cart->asUidQtyMap($user);

		return $this->json([
			'ok' => true,
			'totalQty' => array_sum($map),
			'cart' => $map,
		]);
	}

	#[Route('/cart/remove', name: 'cart_remove', methods: ['POST'])]
	public function remove(Request $request, CartService $cart): JsonResponse
	{
		$this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

		$uid = trim((string)$request->request->get('id', ''));
		if ($uid === '') {
			return $this->json(['ok' => false, 'message' => 'Article invalide.'], 400);
		}

		/** @var \App\Entity\User $user */
		$user = $this->getUser();

		$cart->remove($user, $uid);

		$map = $cart->asUidQtyMap($user);

		return $this->json([
			'ok' => true,
			'totalQty' => array_sum($map),
			'cart' => $map,
		]);
	}

	#[Route('/cart/clear', name: 'cart_clear', methods: ['POST'])]
	public function clear(CartService $cart): RedirectResponse
	{
		$this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

		/** @var \App\Entity\User $user */
		$user = $this->getUser();

		$cart->clear($user);

		return $this->redirectToRoute('cart_index');
	}
}
