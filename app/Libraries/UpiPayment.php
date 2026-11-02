<?php
namespace App\Libraries;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use RuntimeException;

final class UpiPayment
{
    public static function details(string $id,string $name):array
    {
        if(!preg_match('/^[A-Za-z0-9._-]{2,256}@[A-Za-z0-9.-]{2,64}$/D',$id)||$name===''||mb_strlen($name)>150||preg_match('/[\r\n\x00]/',$name))throw new RuntimeException('UPI payment details are not configured correctly. Please contact support.');
        $uri='upi://pay?'.http_build_query(['pa'=>$id,'pn'=>$name,'cu'=>'INR','tn'=>'Primeserve wallet top-up'],'','&',PHP_QUERY_RFC3986);
        $writer=new Writer(new ImageRenderer(new RendererStyle(280,4),new SvgImageBackEnd()));
        return ['id'=>$id,'name'=>$name,'intent'=>$uri,'qrDataUri'=>'data:image/svg+xml;base64,'.base64_encode($writer->writeString($uri))];
    }
}
