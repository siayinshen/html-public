<title><?php echo "$inst_name | {$page_vars['title']}";?></title>
<style>
@font-face {
    font-family: "D-DIN";
    src: url("/fonts/D-DIN.woff2") format("woff2"),
         url("/fonts/D-DIN.woff") format("woff"),
         url("/fonts/D-DIN.ttf") format("truetype"),
         url("/fonts/D-DIN.otf") format("opentype");
    font-style: normal;
    font-weight: 400;
}
@font-face {
    font-family: "D-DIN";
    src: url("/fonts/D-DIN-Bold.woff2") format("woff2"),
         url("/fonts/D-DIN-Bold.woff") format("woff"),
         url("/fonts/D-DIN-Bold.ttf") format("truetype"),
         url("/fonts/D-DIN-Bold.otf") format("opentype");
    font-style: normal;
    font-weight: 700;
}
@font-face {
    font-family: "D-DIN";
    src: url("/fonts/D-DIN-Italic.woff2") format("woff2"),
         url("/fonts/D-DIN-Italic.woff") format("woff"),
         url("/fonts/D-DIN-Italic.ttf") format("truetype"),
         url("/fonts/D-DIN-Italic.otf") format("opentype");
    font-style: italic;
    font-weight: 400;
}
  html,body{
    font-family: D-DIN, 'Helvetica', sans-serif;
    padding: 0;
    margin: 0;
    font-size: max(10px, min(24px, calc(0.6vh + 0.8vw)));
  }
  a{
    text-decoration: inherit;
    color: inherit;
    font-weight: bold;
  }
  input[type='button'],input[type='submit'],input[type='reset']{
    cursor:pointer;
  }
  .header,.footer,.noselect {
  -webkit-touch-callout: none; /* iOS Safari */
    -webkit-user-select: none; /* Safari */
     -khtml-user-select: none; /* Konqueror HTML */
       -moz-user-select: none; /* Old versions of Firefox */
        -ms-user-select: none; /* Internet Explorer/Edge */
            user-select: none;
  }
  .button, .interactive, .clickable{
    cursor: pointer;
  }
  .hide{
    display: none;
  }
  input, textarea,select{
    font-size: 1em;
    font-family: D-DIN, 'Helvetica', sans-serif;
    padding: 0.25em 0.5em;
  }
  textarea{
    min-height: 3.75rem;
    max-height: 50vh;
  }
  table{
    max-width: 100%;
    border-collapse:collapse;
  }
  table > * > td{
    padding: 0.125em 0.25em;
  }
  .interactive:hover{
    text-shadow: 0 0 0.05em #888;
  }
  .message-success{
  
  }
  .message-error{
    color:#e11;
  }
  .message-warn{
    color:#ee1;
  }
  .message-remind{
    font-weight: bold;
    color:#33f;
  }
  .clickable{
    color: #66e !important;
  }
  .clickable:hover{
    text-shadow: 0 0 0.05em #66e;
  }
  .em1{
    font-size: 1.4rem;
    font-weight: bold;
  }
  .em2{
    font-size: 1.2rem;
    font-weight: bold;
  }
  .sm1{
    font-size: 0.9rem;
  }
  .sm2{
    font-size: 0.8rem;
  }
  .sm3{
    font-size: 0.7rem;
  }
  .body-box{
    display: flex;
    flex-grow: grow;
    flex-direction: column;
    min-height: 100vh;
  }
  .input, .button {
    font-family: inherit;
    border: 2px solid #855;
    border-radius: 0.5em;
    font-size: 1em;
    padding: 0.4em;
    margin: 0.25em;
  }
  .button{
    color: #855;
    background: #ebb;
    font-size: 1em;
  }
  .input:disabled, .button:disabled{
    color: #777 !important;
    border: 2px solid #777 !important;
    background: #eee !important;
    -webkit-box-shadow: none !important;
    -moz-box-shadow: none !important;
    box-shadow: none !important;
    cursor: revert !important;
  }
  .button:hover{
    background: #daa;
  }
  .button:hover,.shadow:hover{
    -webkit-box-shadow: 0 0 2px 1px #855;
    -moz-box-shadow: 0 0 2px 1px #855;
    box-shadow: 0 0 2px 1px #855;
  }
  .dropdown {
  position: relative;
}

.dropdown select {
  width: calc(100% - 4px);
}

.dropdown > * {
  box-sizing: border-box;
}

.dropdown input {
  position: absolute;
  width: calc(100% - 1.9em);
}
   /* Draw boxes arund things */
  .generic-info{
    width: calc(100% - 2rem);
    padding: 0 1rem;
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
  }
  .generic-info-box, .generic-info-box-narrow{
    border: 1px solid black;
    padding: 0 1rem;
    margin: 1rem 0 0 0;
  }
  .generic-info-box{
    width: calc(100% - 2rem);
  }
  .generic-info-box-narrow{
    width: calc(50% - 2.5rem);
  }
  /* Handle file type selection */
  .file-picker{
    width: 85%
  }
  .file-picker-row{
    display: flex;
    flex-direction: row;
    width: 100%;
  }
  .file-picker-tab{
    border: 2px solid black;
    background: #efefe3;
    border-bottom: none;
    border-radius: 0 1rem 0 0;
    padding: 0.5rem 1rem 0.2rem 0.5rem;
    margin-right: -0.4rem;
    box-shadow: -0.1rem 0 0.2rem gray;
  }
  .file-picker-tab:first-child{
    box-shadow:none;
  }
  .file-picker-tab:hover, .file-picker-tab-selected{
    background: #fffff3;
    cursor:pointer;
  }
  .file-content{
    border: 2px solid black;
    padding: 2rem;
  }
  /* Handle modals */
  .image-holder{
    width: 10%;
    height: 10%;
    padding: 1%;
    margin-right: 2%;
    background: #fefefe;
    border: 1px solid black;
    border-radius: 3%;
    cursor:pointer;
  }
  .image-holder:hover{
    box-shadow: 0.1rem 0.1rem 0.4rem gray;
  }
  .image-holder>img{
    max-width: 100%;
  }
  .floating-modal-anchor{
    position: sticky;
    left:0;
    top:0;
    width:1px;
    height:1px;
    z-index:3;
  }
  .floating-modal-lid{
    position: absolute;
    left:0;
    top:0;
    width:100%;
    height:100%;
    z-index:6;
  }
  .floating-modal-close{
    position: absolute;
    font-weight: bold;
    cursor: pointer;
    color: #f00;
    background: #faa;
    border-radius: 0.5rem;
    border: 2px solid red;
    font-size: 2rem;
    right:1rem;
    top:1rem;
    width:2.2rem;
    height:2.2rem;
    z-index:6;
  }
  .floating-modal-close:hover{
    background: #fcc;
    color: #f11;
    box-shadow: 0.1rem 0.1rem 0.4rem gray;
  }
  .floating-modal-close>span{
    position: absolute;
    left: 50%;
    top: 60%;
    transform:translate(-50%,-50%);
  }
  .floating-modal-parent{
    position: sticky;
    left: 0;
    top: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(100,100,100,0.5);
    height: 100vh;
    width: 100vw;
    z-index:4;
  }
  .floating-modal{
    display: flex;
    padding: 2%;
    justify-content: center;
    position: relative;
    width: 86%;
    height: 86%;
    overflow-y: scroll;
    border-radius: 1rem;
    background: #ffffff;
    z-index:5;
  }
  .floating-modal>img{
    height: 100%;
    max-height: 100%;
    max-width: 100%;
  }
  .floating-modal>embed{
    width: 100%;
    max-width: 100%;
  }
  @media only all and (orientation: portrait) {
    body {
        font-size:min(24px, calc(0.8vh + 0.8vw));
    }
    .generic-info-box-narrow{
      width: calc(100% - 2rem);
    }
    .dropdown input {
      width: calc(100% - 2.3em);
    }
  }
</style>
<script>
<?php //Helper JS functions go here?>
function htmlentities(unsafe){
  return unsafe
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}
function unhtmlentities(safe){
  return safe
    .replace(/(&amp;)/g, "&")
    .replace(/(&lt;)/g, "<")
    .replace(/(&gt;)/g, ">")
    .replace(/(&quot;)/g, '"')
    .replace(/(&#039;)/g, "'");
}
<?php
if(isset($authed_user)){
  echo("const authed_user=". json_encode($authed_user));
}else if(isset($authed_patient)){
  echo("const authed_patient=". json_encode($authed_patient));
}
?>
</script>
