<?php

namespace Bryanthw1020\LaravelEwsDriver\Transport;

use Bryanthw1020\LaravelEwsDriver\Exceptions\EwsException;
use Illuminate\Mail\Transport\Transport;
use jamesiarmes\PhpEws\ArrayType\ArrayOfRecipientsType;
use jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfAllItemsType;
use jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfAttachmentsType;
use jamesiarmes\PhpEws\ArrayType\NonEmptyArrayOfBaseItemIdsType;
use jamesiarmes\PhpEws\Client;
use jamesiarmes\PhpEws\Enumeration\BodyTypeType;
use jamesiarmes\PhpEws\Enumeration\DistinguishedFolderIdNameType;
use jamesiarmes\PhpEws\Enumeration\MessageDispositionType;
use jamesiarmes\PhpEws\Enumeration\ResponseClassType;
use jamesiarmes\PhpEws\Request\CreateAttachmentType;
use jamesiarmes\PhpEws\Request\CreateItemType;
use jamesiarmes\PhpEws\Request\SendItemType;
use jamesiarmes\PhpEws\Type\BodyType;
use jamesiarmes\PhpEws\Type\DistinguishedFolderIdType;
use jamesiarmes\PhpEws\Type\EmailAddressType;
use jamesiarmes\PhpEws\Type\FileAttachmentType;
use jamesiarmes\PhpEws\Type\ItemIdType;
use jamesiarmes\PhpEws\Type\MessageType;
use jamesiarmes\PhpEws\Type\SingleRecipientType;
use jamesiarmes\PhpEws\Type\TargetFolderIdType;
use Swift_Mime_SimpleMessage;
use Swift_Mime_SimpleMimeEntity;

class ExchangeTransport extends Transport
{
    /**
     * EWS Host
     *
     * @var string
     */
    protected $host;

    /**
     * EWS Account Username
     *
     * @var string
     */
    protected $username;

    /**
     * EWS Account Password
     *
     * @var string
     */
    protected $password;

    /**
     * EWS Client
     *
     * @var Client
     */
    protected $client;

    /**
     * EWS Attachments
     *
     * @var Swift_Mime_SimpleMimeEntity[]
     */
    protected $attachments;

    public function __construct($host, $username, $password)
    {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->client = new Client($this->host, $this->username, $this->password);
    }

    public function send(Swift_Mime_SimpleMessage $simpleMessage, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($simpleMessage);

        $this->attachments = $simpleMessage->getChildren();
        $message = $this->createMessage($simpleMessage);
        $request = $this->createItemRequest($message);
        $item = $this->createItem($request);
        $messageId = $item['item_id'];
        $changeKey = $item['change_key'];

        $request = new SendItemType();
        $request->SaveItemToFolder = true;
        $request->ItemIds = new NonEmptyArrayOfBaseItemIdsType();

        $item = new ItemIdType();
        $item->Id = $messageId;
        $item->ChangeKey = $changeKey;
        $request->ItemIds->ItemId[] = $item;

        $sendFolder = new TargetFolderIdType();
        $sendFolder->DistinguishedFolderId = new DistinguishedFolderIdType();
        $sendFolder->DistinguishedFolderId->Id = DistinguishedFolderIdNameType::SENT;
        $request->SavedItemFolderId = $sendFolder;

        $response = $this->client->SendItem($request);
        $responseMessages = $response->ResponseMessages->SendItemResponseMessage;

        foreach ($responseMessages as $responseMessage) {
            if ($responseMessage->ResponseClass !== ResponseClassType::SUCCESS) {
                throw new EwsException($responseMessage->MessageText);
            }
        }

        $this->sendPerformed($simpleMessage);

        return $this->numberOfRecipients($simpleMessage);
    }

    /**
     * Create Message
     *
     * @param Swift_Mime_SimpleMessage $simpleMessage
     * @return MessageType
     */
    private function createMessage(Swift_Mime_SimpleMessage $simpleMessage)
    {
        $message = new MessageType();
        $message->Subject = $simpleMessage->getSubject();
        $message->ToRecipients = new ArrayOfRecipientsType();
        $message->CcRecipients = new ArrayOfRecipientsType();
        $message->BccRecipients = new ArrayOfRecipientsType();

        // Set the sender.
        $message->From = new SingleRecipientType();
        $message->From->Mailbox = new EmailAddressType();
        $message->From->Mailbox->EmailAddress = config('mail.from.address');
        $message->From->Mailbox->Name = config('mail.from.name');

        // Set the to recipient.
        foreach ($simpleMessage->getTo() as $email => $name) {
            $recipient = $this->createRecipient($email, $name);
            $message->ToRecipients->Mailbox[] = $recipient;
        }

        // Set the cc recipient
        if ($simpleMessage->getCc()) {
            foreach ($simpleMessage->getCc() as $email => $name) {
                $recipient = $this->createRecipient($email, $name);
                $message->CcRecipients->Mailbox[] = $recipient;
            }
        }

        // // Set the bcc recipient
        if ($simpleMessage->getBcc()) {
            foreach ($simpleMessage->getBcc() as $email => $name) {
                $recipient = $this->createRecipient($email, $name);
                $message->BccRecipients->Mailbox[] = $recipient;
            }
        }

        // Set the message body.
        $message->Body = new BodyType();
        $message->Body->BodyType = BodyTypeType::HTML;
        $message->Body->_ = $simpleMessage->getBody();

        return $message;
    }

    /**
     * Create Recipient
     *
     * @param string $email
     * @param string|null $name
     * @return EmailAddressType
     */
    private function createRecipient(string $email, ?string $name)
    {
        $recipient = new EmailAddressType();
        $recipient->EmailAddress = $email;

        if ($name !== null) {
            $recipient->Name = $name;
        }

        return $recipient;
    }

    /**
     * Create Item Request
     *
     * @param MessageType $message
     * @return CreateItemType
     */
    private function createItemRequest(MessageType $message)
    {
        $request = new CreateItemType();
        $request->Items = new NonEmptyArrayOfAllItemsType();
        $request->Items->Message[] = $message;
        $request->MessageDisposition = MessageDispositionType::SAVE_ONLY;

        return $request;
    }

    /**
     * Create Item
     *
     * @param CreateItemType $request
     * @return array
     */
    private function createItem(CreateItemType $request)
    {
        $data = [];
        $response = $this->client->CreateItem($request);
        $responseMessages = $response->ResponseMessages->CreateItemResponseMessage;

        foreach ($responseMessages as $responseMessage) {
            if ($responseMessage->ResponseClass !== ResponseClassType::SUCCESS) {
                throw new EwsException($responseMessage->MessageText);
            }

            foreach ($responseMessage->Items->Message as $item) {
                $data['code'] = 0;
                $data['item_id'] = $item->ItemId->Id;
                $data['change_key'] = $item->ItemId->ChangeKey;
            }
        }

        if ($data['code'] === 0 && !empty($this->attachments)) {
            $data = $this->addAttachment($data['item_id']);
        }

        return $data;
    }

    private function addAttachment(string $itemId)
    {
        $data = [];
        $request = new CreateAttachmentType();
        $request->ParentItemId = new ItemIdType();
        $request->ParentItemId->Id = $itemId;
        $request->Attachments = new NonEmptyArrayOfAttachmentsType();

        foreach ($this->attachments as $file) {
            $attachment = new FileAttachmentType();
            $attachment->name = $file->getFilename();
            $attachment->Content = $file->getBody();
            $attachment->ContentType = $file->getContentType();
            $request->Attachments->FileAttachment[] = $attachment;
        }

        $response = $this->client->CreateAttachment($request);
        $responseMessages = $response->ResponseMessages->CreateAttachmentResponseMessage;

        foreach ($responseMessages as $responseMessage) {
            if ($responseMessage->ResponseClass !== ResponseClassType::SUCCESS) {
                throw new EwsException($responseMessage->MessageText);
            }

            foreach ($responseMessage->Attachments->FileAttachment as $attachment) {
                $data['code'] = 0;
                $data['item_id'] = $attachment->AttachmentId->RootItemId;
                $data['change_key'] = $attachment->AttachmentId->RootItemChangeKey;
                $data['attachment_id'] = $attachment->AttachmentId->Id;
            }
        }

        return $data;
    }
}
