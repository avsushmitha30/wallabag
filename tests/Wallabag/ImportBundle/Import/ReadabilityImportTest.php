<?php

namespace Tests\Wallabag\ImportBundle\Import;

use Wallabag\ImportBundle\Import\ReadabilityImport;
use Wallabag\UserBundle\Entity\User;
use Wallabag\CoreBundle\Entity\Entry;
use Monolog\Logger;
use Monolog\Handler\TestHandler;

class ReadabilityImportTest extends \PHPUnit_Framework_TestCase
{
    protected $user;
    protected $em;
    protected $logHandler;
    protected $contentProxy;

    private function getReadabilityImport($unsetUser = false)
    {
        $this->user = new User();

        $this->em = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->contentProxy = $this->getMockBuilder('Wallabag\CoreBundle\Helper\ContentProxy')
            ->disableOriginalConstructor()
            ->getMock();

        $wallabag = new ReadabilityImport($this->em, $this->contentProxy);

        $this->logHandler = new TestHandler();
        $logger = new Logger('test', [$this->logHandler]);
        $wallabag->setLogger($logger);

        if (false === $unsetUser) {
            $wallabag->setUser($this->user);
        }

        return $wallabag;
    }

    public function testInit()
    {
        $readabilityImport = $this->getReadabilityImport();

        $this->assertEquals('Readability', $readabilityImport->getName());
        $this->assertNotEmpty($readabilityImport->getUrl());
        $this->assertEquals('import.readability.description', $readabilityImport->getDescription());
    }

    public function testImport()
    {
        $readabilityImport = $this->getReadabilityImport();
        $readabilityImport->setFilepath(__DIR__.'/../fixtures/readability.json');

        $entryRepo = $this->getMockBuilder('Wallabag\CoreBundle\Repository\EntryRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $entryRepo->expects($this->exactly(2))
            ->method('findByUrlAndUserId')
            ->will($this->onConsecutiveCalls(false, true));

        $this->em
            ->expects($this->any())
            ->method('getRepository')
            ->willReturn($entryRepo);

        $entry = $this->getMockBuilder('Wallabag\CoreBundle\Entity\Entry')
            ->disableOriginalConstructor()
            ->getMock();

        $this->contentProxy
            ->expects($this->exactly(1))
            ->method('updateEntry')
            ->willReturn($entry);

        $res = $readabilityImport->import();

        $this->assertTrue($res);
        $this->assertEquals(['skipped' => 1, 'imported' => 1], $readabilityImport->getSummary());
    }

    public function testImportAndMarkAllAsRead()
    {
        $readabilityImport = $this->getReadabilityImport();
        $readabilityImport->setFilepath(__DIR__.'/../fixtures/readability-read.json');

        $entryRepo = $this->getMockBuilder('Wallabag\CoreBundle\Repository\EntryRepository')
            ->disableOriginalConstructor()
            ->getMock();

        $entryRepo->expects($this->exactly(2))
            ->method('findByUrlAndUserId')
            ->will($this->onConsecutiveCalls(false, false));

        $this->em
            ->expects($this->any())
            ->method('getRepository')
            ->willReturn($entryRepo);

        $this->contentProxy
            ->expects($this->exactly(2))
            ->method('updateEntry')
            ->willReturn(new Entry($this->user));

        // check that every entry persisted are archived
        $this->em
            ->expects($this->any())
            ->method('persist')
            ->with($this->callback(function ($persistedEntry) {
                return $persistedEntry->isArchived();
            }));

        $res = $readabilityImport->setMarkAsRead(true)->import();

        $this->assertTrue($res);

        $this->assertEquals(['skipped' => 0, 'imported' => 2], $readabilityImport->getSummary());
    }

    public function testImportBadFile()
    {
        $readabilityImport = $this->getReadabilityImport();
        $readabilityImport->setFilepath(__DIR__.'/../fixtures/wallabag-v1.jsonx');

        $res = $readabilityImport->import();

        $this->assertFalse($res);

        $records = $this->logHandler->getRecords();
        $this->assertContains('ReadabilityImport: unable to read file', $records[0]['message']);
        $this->assertEquals('ERROR', $records[0]['level_name']);
    }

    public function testImportUserNotDefined()
    {
        $readabilityImport = $this->getReadabilityImport(true);
        $readabilityImport->setFilepath(__DIR__.'/../fixtures/readability.json');

        $res = $readabilityImport->import();

        $this->assertFalse($res);

        $records = $this->logHandler->getRecords();
        $this->assertContains('ReadabilityImport: user is not defined', $records[0]['message']);
        $this->assertEquals('ERROR', $records[0]['level_name']);
    }
}