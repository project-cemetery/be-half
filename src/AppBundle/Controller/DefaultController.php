<?php

namespace AppBundle\Controller;

use AppBundle\Entity\User;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

class DefaultController extends Controller
{
    /**
     * @Route("/", name="homepage")
     */
    public function indexAction(Request $request)
    {
        $userCount = count($this->getDoctrine()->getRepository(User::class)->findAll());

        return $this->render(
            'landing.html.twig',
            [
                'userCount' => 1,
            ]
            );
    }
}
