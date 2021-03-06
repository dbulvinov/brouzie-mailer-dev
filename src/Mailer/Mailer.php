<?php

namespace Brouzie\Mailer;

use Brouzie\Mailer\Container\SimpleServiceLocator;
use Brouzie\Mailer\Exception\InvalidArgumentException;
use Brouzie\Mailer\Exception\PredefinedEmailNotFoundException;
use Brouzie\Mailer\Exception\RendererNotFoundException;
use Brouzie\Mailer\Exception\TransportNotFoundException;
use Brouzie\Mailer\Model\Address;
use Brouzie\Mailer\Model\Email;
use Brouzie\Mailer\Model\PredefinedEmail;
use Brouzie\Mailer\Renderer\Renderer;
use Brouzie\Mailer\Transport\Transport;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

class Mailer
{
    private $renderer;

    /**
     * @var ContainerInterface
     */
    private $transports;

    private $defaultTransport;

    private $defaultSender;

    /**
     * @var ContainerInterface
     */
    private $predefinedEmails;

    private $defaultContext;

    private $defaultHeaders;

    /**
     * @param Renderer $renderer
     * @param array|Transport[]|ContainerInterface $transports
     * @param string $defaultTransport
     * @param Address $defaultSender
     * @param array|PredefinedEmail[]|ContainerInterface $predefinedEmails
     * @param array $defaultContext
     * @param array $defaultHeaders
     */
    public function __construct(
        Renderer $renderer,
        $transports,
        string $defaultTransport = 'default',
        Address $defaultSender,
        $predefinedEmails = [],
        array $defaultContext = [],
        array $defaultHeaders = []
    ) {
        $this->renderer = $renderer;

        if ($transports instanceof ContainerInterface) {
            $this->transports = $transports;
        } elseif (is_array($transports)) {
            $this->transports = new SimpleServiceLocator($transports);
        } else {
            throw new InvalidArgumentException(
                'Expected an array of transports or "Psr\Container\ContainerInterface" instance.'
            );
        }

        $this->defaultTransport = $defaultTransport;
        if (!$this->transports->has($defaultTransport)) {
            throw new TransportNotFoundException(sprintf('Missing default transport "%s".', $defaultTransport));
        }

        $this->defaultSender = $defaultSender;

        if ($predefinedEmails instanceof ContainerInterface) {
            $this->predefinedEmails = $predefinedEmails;
        } elseif (is_array($predefinedEmails)) {
            $this->predefinedEmails = new SimpleServiceLocator($predefinedEmails);
        } else {
            throw new InvalidArgumentException(
                'Expected an array of predefined emails or "Psr\Container\ContainerInterface" instance.'
            );
        }

        $this->defaultContext = $defaultContext;
        $this->defaultHeaders = $defaultHeaders;
    }

    public function sendEmail(Email $email, array $context = [], $transport = null)
    {
        //TODO: add events?
        $context = array_replace($this->defaultContext, $context, ['_email' => $email]);
        $email->addHeaders($this->defaultHeaders);

        //TODO: add support of already rendered emails?
        if (!$this->renderer->supports($email)) {
            throw new RendererNotFoundException(sprintf('No renderer found for email of type "%s".', get_class($email)));
        }

        $this->renderer->render($email, $context);

        if (null === $email->getSender()) {
            $email->setSender($this->defaultSender);
        }

        $this->getTransport($transport)->send($email);
    }

    /**
     * @param $name
     * @param string|iterable|Address|Address[] $recipients
     * @param array $context
     * @param callable|null $configurator
     */
    public function sendNamedEmail(string $name, $recipients, array $context = [], callable $configurator = null)
    {
        /** @var PredefinedEmail $predefinedEmail */
        try {
            $predefinedEmail = $this->predefinedEmails->get($name);
        } catch (NotFoundExceptionInterface $e) {
            $availableEmails = method_exists($e, 'getAlternatives') ? $e->getAlternatives() : [];

            throw PredefinedEmailNotFoundException::create($name, $availableEmails, $e);
        }

        $context = array_replace($predefinedEmail->getDefaultContext(), $context, ['_email_name' => $name]);
        $predefinedEmail->validateContext($context);

        $email = $predefinedEmail->getEmail();
        $email->addRecipients(self::normalizeRecipients($recipients));

        if (null !== $configurator) {
            $configurator($email, $context);
        }

        $this->sendEmail($email, $context, $predefinedEmail->getTransport());
    }

    /**
     * @param string|iterable|Address|Address[] $recipients
     *
     * @return Address[]
     */
    public static function normalizeRecipients($recipients): array
    {
        $targetRecipients = [];
        if (is_string($recipients)) {
            $targetRecipients[] = new Address($recipients);
        } elseif ($recipients instanceof Address) {
            $targetRecipients[] = $recipients;
        } elseif (is_iterable($recipients)) {
            foreach ($recipients as $key => $recipient) {
                if ($recipient instanceof Address) {
                    $targetRecipients[] = $recipient;
                } elseif (is_int($key)) {
                    $targetRecipients[] = new Address($recipient);
                } else {
                    $targetRecipients[] = new Address($key, $recipient);
                }
            }
        } else {
            throw new InvalidArgumentException('Expected string/array/Address instance/array of Address instances.');
        }

        return $targetRecipients;
    }

    private function getTransport($transport): Transport
    {
        $transport = $transport ?: $this->defaultTransport;

        try {
            return $this->transports->get($transport);
        } catch (NotFoundExceptionInterface $e) {
            $availableTransports = method_exists($e, 'getAlternatives') ? $e->getAlternatives() : [];

            throw TransportNotFoundException::create($transport, $availableTransports, $e);
        }
    }
}
