<?php

namespace AppBundle\Command;

use AppBundle\Entity\Flat;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PHPHtmlParser\Dom;
use Mailgun\Mailgun;

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
            ->setDescription('Parse')
            ->addArgument('search', InputArgument::REQUIRED)
            ->addArgument('link', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->em = $this->getContainer()->get('doctrine.orm.entity_manager');
        $search = $input->getArgument('search');
        $link = $input->getArgument('link');

        while (1) {
            $this->parse($search, $link, $output);
            sleep(60 * 5);
        }


    }

    protected function parse($search, $link, OutputInterface $output)
    {
        $dom = new Dom;
        $dom->load($link);
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
            'price' => $price,
            'search' => $search
        ];

        $records = $this->em->getRepository('AppBundle:Flat')
            ->findBy([
                'search' => $search,
                'title'  => $result['title']
            ]);

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
        $record->setSearch($result['search']);
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
        $address2 = $this->getContainer()->getParameter('address2');

        $subject = $result['price'] . ' : ' . trim($result['title']);

        $body = sprintf($this->getView(), $result['href'], $result['title'], $result['src'], $result['additionalText']);

        foreach ($result['photos'] as $photo) {
            $body .= sprintf("<br /><img src='%s' />", $photo);
        }

        $excludeWords = $this->getContainer()->getParameter('exclude_words');

        foreach ($excludeWords as $excludeWord) {
            if (substr_count($subject, $excludeWord) || substr_count($subject, $body)) {
                return;
            }
        }



        $key = $this->getContainer()->getParameter('mailgun_key');
        $domain = $this->getContainer()->getParameter('mailgun_domain');

        $mg = new Mailgun($key);

        # Now, compose and send your message.
        $mg->sendMessage($domain, array(
            'from'    => $address1,
            'to'      => $address1,
            'subject' => $subject,
            'html'    => $body));

        $mg->sendMessage($domain, array(
            'from'    => $address1,
            'to'      => $address2,
            'subject' => $subject,
            'html'    => $body));
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