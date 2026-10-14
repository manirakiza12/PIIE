<?php
namespace App\Support\Library;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LibraryDigitalStorage
{
    public function store(Request $request, User $actor, string $folder = 'resources'): array
    {
        $policy=app(LibraryService::class)->policy($actor->school_id);
        $request->validate(['digital_file'=>'required|file|max:20480']);
        $file=$request->file('digital_file'); $extension=strtolower($file->getClientOriginalExtension());
        if(!in_array($extension,$policy->allow_epub ? ['pdf','epub'] : ['pdf'],true)) app(LibraryService::class)->fail('Upload a PDF'.($policy->allow_epub?' or EPUB':'').'. The administrator controls allowed formats.','digital_file');
        if($extension==='pdf') {
            $handle=fopen($file->getRealPath(),'rb'); $header=fread($handle,5); fclose($handle);
            if($header!=='%PDF-' || !in_array($file->getMimeType(),['application/pdf','application/x-pdf'],true)) app(LibraryService::class)->fail('The uploaded file is not a valid PDF.','digital_file');
        } else {
            $zip=new \ZipArchive();
            if($zip->open($file->getRealPath())!==true) app(LibraryService::class)->fail('The uploaded file is not an EPUB archive.','digital_file');
            $stat=$zip->statName('mimetype');
            $valid=$stat && $stat['size']<512 && trim((string)$zip->getFromName('mimetype'))==='application/epub+zip' && $zip->locateName('META-INF/container.xml')!==false;
            $zip->close(); if(!$valid) app(LibraryService::class)->fail('The uploaded file is not a valid EPUB.','digital_file');
        }
        $name=trim(preg_replace('/[^a-zA-Z0-9 ._-]/','',basename(str_replace('\\','/',$file->getClientOriginalName()))));
        $path=$file->storeAs('library/'.$actor->school_id.'/'.$folder,Str::uuid().'.'.$extension,'local');
        if(!$path) throw new \RuntimeException('Digital resource storage failed.');
        return ['digital_path'=>$path,'digital_name'=>substr($name ?: 'resource.'.$extension,0,200),'digital_mime'=>$extension==='pdf'?'application/pdf':'application/epub+zip'];
    }

    public function download(User $actor, object $record)
    {
        $path=(string)$record->digital_path;
        abort_unless(preg_match('~^library/'.(int)$actor->school_id.'/(?:resources|requests)/[a-f0-9-]{36}\.(?:pdf|epub)$~',$path) && Storage::disk('local')->exists($path),404);
        return Storage::disk('local')->download($path,$record->digital_name ?: 'library-resource.pdf',[
            'Content-Type'=>$record->digital_mime ?: 'application/octet-stream','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
