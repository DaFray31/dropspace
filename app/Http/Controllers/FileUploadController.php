<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class FileUploadController extends Controller
{
    //

    public function uploadCLIChunks(){
        Log::info('Received chunk from CLI: ' . request()->get('chunkNumber'));
        //Save file to disk named "{chunkNumber}-dropspace-cli.part"
        if(request()->has('file')){
            Storage::put('dropspace/temp/', request()->file('file'));
        }else{
            Log::error('No file received from CLI');
            return response()->json(['error' => 'No file received from CLI'], 400);
        }
        return 'Successfully uploaded chunk: ' . request()->get('chunkNumber');
    }

    public function uploadChunks()
    {
        //This is gonna be a blast to write


        $totalChunks = request()->resumableTotalChunks;
        $chunkNumber = request()->resumableChunkNumber;
        $resumableIdentifier = request()->resumableIdentifier;
        $clientFilename = request()->resumableFilename;

        if ($totalChunks == 1) {
            //Save file to storage and database
            $file = new File;
            $file->name = $clientFilename;
            $file->extension = pathinfo($clientFilename, PATHINFO_EXTENSION);
            $file->size = request()->resumableTotalSize;
            if (request()->hasHeader('CF-Connecting-IP')) {
                //The IP adress of the uploader is saved in the database, when the application is set up with Cloudflare, this IP adress is changed to Cloudflare's IP adress, but the IP adress of the client is passed through in a header, that's called 'CF-Connecting-IP'
                $file->uploader_ip = request()->header('CF-Connecting-IP');
            } else {
                //If the application is not set up with Cloudflare, getting the IP adress of the client is grabbed straight from the request
                $file->uploader_ip = request()->ip();
            }
            $file->file_identifier = Str::random(12);
            while (DB::table('files')->where('file_identifier', $file->file_identifier)->exists()) {
                $file->file_identifier = Str::random(12);
            }
            Storage::putFileAs('dropspace/uploads/', request()->file('file'), $file->file_identifier.'.'.$file->extension);
            $file->path = $file->file_identifier . '.' . $file->extension;
            $file->save();
            return response()->json(['success' => true, 'identifier' => $file->file_identifier]);
        }
        //If this is the first chunk, create a new file
        if ($chunkNumber == 1) {
            //Save file to storage
            Storage::putFileAs('dropspace/chunks/' . $resumableIdentifier, request()->file('file'), $chunkNumber . '-' . $resumableIdentifier);
        } else {
            Storage::putFileAs('dropspace/chunks/' . $resumableIdentifier, request()->file('file'), $chunkNumber . '-' . $resumableIdentifier);
        }
        //If the chunk number is the same as the total chunks, combine the chunks and save the file
        if ($chunkNumber == $totalChunks) {
            //Create new file
            $file = new File;
            $file->name = $clientFilename;
            //get extension from clientFilename
            $file->extension = pathinfo($clientFilename, PATHINFO_EXTENSION);
            $file->size = request()->resumableTotalSize;
            if (request()->hasHeader('CF-Connecting-IP')) {
                //The IP adress of the uploader is saved in the database, when the application is set up with Cloudflare, this IP adress is changed to Cloudflare's IP adress, but the IP adress of the client is passed through in a header, that's called 'CF-Connecting-IP'
                $file->uploader_ip = request()->header('CF-Connecting-IP');
            } else {
                //If the application is not set up with Cloudflare, getting the IP adress of the client is grabbed straight from the request
                $file->uploader_ip = request()->ip();
            }

            //generate file_identifier
            $file->file_identifier = Str::random(12);
            while (DB::table('files')->where('file_identifier', $file->file_identifier)->exists()) {
                $file->file_identifier = Str::random(12);
            }
            //make empty file in storage



            Storage::put('dropspace/temp/' . $resumableIdentifier . '-' . $clientFilename, '');
            $fp = fopen('../storage/app/dropspace/temp/' . $resumableIdentifier . '-' . $clientFilename, 'w'); //opens file in append mode  
            for ($i = 1; $i <= $totalChunks; $i++) {
                //Get the chunk file
                $chunkFile = Storage::get('dropspace/chunks/' . $resumableIdentifier . '/' . $i . '-' . $resumableIdentifier);
                //Get client filename
                $clientFilename = request()->resumableFilename;
                //Storage::append('dropspace/chunks/'.$resumableIdentifier.'-'.$clientFilename, $chunkFile, null);
                fwrite($fp, $chunkFile);
                Log::info('Appended chunk: ' . $i . ' from file:' . 'dropspace/chunks/' . $resumableIdentifier . '/' . $i . '-' . $resumableIdentifier);
                //Delete the chunk file
                //Storage::delete('dropspace/chunks/'.$resumableIdentifier.'/'.$i.'-'.$resumableIdentifier);
            }
            fclose($fp);
            Storage::deleteDirectory('dropspace/chunks/'.$resumableIdentifier);
            Storage::move('dropspace/temp/' . $resumableIdentifier . '-' . $clientFilename, 'dropspace/uploads/' . $file->file_identifier . '.' . $file->extension);
            $file->path = $file->file_identifier . '.' . $file->extension;
            $file->save();
            //Make MD5 hash of file
            $md5 = md5_file('../storage/app/dropspace/uploads/' . $file->file_identifier . '.' . $file->extension);
            return response()->json(['success' => true, 'identifier' => $file->file_identifier,'md5' => $md5]);
        }
        return response()->json(['success' => true, 'chunkNumber' => $chunkNumber, 'totalChunks' => $totalChunks]);
    }

    function byteConvert($bytes)
{
    if ($bytes == 0)
        return "0.00 B";

    $s = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
    $e = floor(log($bytes, 1024));

    return round($bytes/pow(1024, $e), 2).$s[$e];
}

    public function setFileDetails($id)
    {
        //This function is called when the user wants to set the file's details, this is the view that is shown to the user
        $file = File::where('file_identifier', $id)->first();
        if ($file == null) {
            //If the file doesn't exist, the user is redirected to the error page
            return view('download-error', ['error' => "File doesn't exist."]);
        }
        if ($file->finished_uploading == true) {
            return view('download-error', ['error' => "This file has already been uploaded before, you can't modify the settings."]);
        }
        //Filesize in human readable format from $file->size
        return view('upload-settings', ['fileName' => $file->name, 'uploadDate' => $file->created_at, 'fileID' => $file->file_identifier, 'size' => $this->byteConvert($file->size)]);
    }

    public function saveFileDetails($id, Request $request)
    {
        $file = File::where('file_identifier', $id)->first();
        if ($file == null) {
            return view('download-error', ['error' => "File doesn't exist."]);
        }
        if ($file->finished_uploading == true) {
            return view('download-error', ['error' => "This file has already been uploaded before, you can't modify the settings."]);
        }
        //Saves the file's additional details to the database
        $file->download_limit = $request->input('dlimit');
        $file->download_count = 0;
        $expiryDate = $request->input('expiry');
        //cases for the expiry date, never, 1 week, 1 month, 1 year
        if ($expiryDate == "never") {
            $file->expiry_date = null;
        } elseif ($expiryDate == "1-day") {
            $file->expiry_date = date('Y-m-d H:i:s', strtotime('+1 day'));
        } elseif ($expiryDate == "1-week") {
            $file->expiry_date = date('Y-m-d H:i:s', strtotime('+1 week'));
        } elseif ($expiryDate == "1-month") {
            $file->expiry_date = date('Y-m-d H:i:s', strtotime('+1 month'));
        } elseif ($expiryDate == "1-year") {
            $file->expiry_date = date('Y-m-d H:i:s', strtotime('+1 year'));
        }
        if (request()->passbool == "true") {
            $file->is_protected = true;
            $file->password = sha1($request->input('password'));
        } else {
            $file->is_protected = false;
            $file->password = null;
        }
        $file->finished_uploading = true;
        $file->save();
        if ($file->is_protected) {
            //return redirect to download with password
            //This redirects the user to the download page, with the file's identifier and the password as parameters
            return redirect('/download/' . $file->file_identifier . '?hash=' . $file->password);
        } else {
            //return redirect to download without password
            //This redirects the user to the download page, with the file's identifier.
            return redirect('/download/' . $file->file_identifier);
        }
    }
}
