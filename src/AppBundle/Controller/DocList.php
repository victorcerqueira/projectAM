<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
//use Symfony\Component\HttpFoundation\Request;

class DocList extends Controller
{

    /**
     * @Route("/docs/list")
     */
    public function indexAction()//Request $request
    {
        // replace this example code with whatever you need
        return $this->render('docs/list.html.twig', array(
            'base_dir' => realpath($this->container->getParameter('kernel.root_dir').'/..'),
        ));
    }

}
