<?php

namespace AppBundle\Command;

use AppBundle\Entity\Flat;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PHPHtmlParser\Dom;

class ParseCommand extends ContainerAwareCommand
{
    /**
     * @var EntityManager
     */
    protected $em;

    protected function configure()
    {
        $this
            ->setName('flat:parse')
            ->setDescription('Parse');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->em = $this->getContainer()->get('doctrine.orm.entity_manager');

        $this->parse($output);
    }

    protected function parse(OutputInterface $output)
    {
        $dom = new Dom;
        //$dom->load($this->getContainer()->getParameter('target_link'));
        $dom->load('http://kiev.ko.olx.ua/nedvizhimost/arenda-kvartir/dolgosrochnaya-arenda-kvartir/?search%5Bfilter_float_price%3Ato%5D=8000&search%5Bdistrict_id%5D=19');
        $offers = $dom->find('#offers_table .offer');

        if (!count($offers)) {
            return;
        }

        $result = [];

        $offer = current(current($offers));

        $img = $offer->find('img.fleft');
        $src = $img->getAttribute('src');

        $link = $offer->find('.detailsLink');
        $href = $link->getAttribute('href');

        $textTag = $offer->find('.detailsLink strong');
        $text = $textTag->text;

        $priceTag = $offer->find('.price strong');
        $price = $priceTag->text;

        $result = [
            'src'   => $src,
            'href'  => $href,
            'title' => trim($text),
            'price' => $price
        ];

        $records = $this->em->getRepository('AppBundle:Flat')
            ->findAll();

        if (count($records)) {
            $record = current($records);
        } else {
            $record = new Flat();
        }

        $this->compare($record, $result);
    }

    protected function compare(Flat $record, $result)
    {
        if ($record->getTitle() != $result['title'] || $record->getPrice() != $result['price']) {

            $this->updateRecord($record, $result);

            $this->addAdditionalInfo($result);

            $this->send($result);

            print_r($result);
        }
    }

    protected function addAdditionalInfo(&$result)
    {
        $dom = new Dom;

        $dom->load($result['href']);
        $additionalInfoTag = $dom->find('#textContent p');

        $result['additionalText'] = $additionalInfoTag->text;

        $photoTags = $dom->find('.img-item img');

        $photos = [];

        foreach ($photoTags as $photoTag) {
            $photos[] = $photoTag->getAttribute('src');
        }

        $result['photos'] = $photos;
    }

    protected function updateRecord(Flat $record, $result)
    {
        $record->setTitle($result['title']);
        $record->setPrice($result['price']);
        $record->setHref($result['href']);
        $record->setSrc($result['src']);

        $this->em->persist($record);
        $this->em->flush($record);


    }

    protected function send($result)
    {
        $address1 = $this->getContainer()->getParameter('address1');

        $subject = $result['price'] . ' : ' . trim($result['title']);

        $message = \Swift_Message::newInstance();

        $body = sprintf($this->getView(), $result['href'], $result['title'], $result['src'], $result['additionalText']);

        foreach ($result['photos'] as $photo) {
            $body .= sprintf("<br /><img src='%s' />", $photo);
        }

        $message
            ->setSubject($subject)
            ->setFrom($address1)
            ->setTo($address1)
            ->setBody(
                $body,
                'text/html'
            );

        $transport = \Swift_SmtpTransport::newInstance('127.0.0.1', 1025);

        $mailer = \Swift_Mailer::newInstance($transport);

        $mailer->send($message);
    }

    protected function getView()
    {
        return "
        <a href='%s'>
            <p>%s</p>
            <img src='%s' />
        </a>
        <p>%s</p>
        ";
    }
}