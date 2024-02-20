<style>
  .header{
    background: linear-gradient(#fffefe,#fee);
    padding: 0.5rem 1rem 0 1rem;
    height: 6em;
    display: flex;
    justify-content: flex-end;
    align-items:center;
    flex-direction: column;
    font-weight: bold;
  }
  .header-title{
    font-size: 2rem;
    padding: 0.5rem 0;
  }
  .header-menu{
    display: flex;
    justify-content: space-between;
    flex-direction: row;
    font-size: 1.5rem;
    font-weight: normal;
    width: 80%;
    
  }
  .header-menu-item{
    transition: border 0.4s;
    padding: 0.35rem 0.5rem;
    margin-right: 0.5rem;
    border-bottom: 4px solid #fee;
    font-weight: normal;
  }
  .header-menu-item:hover{
    border-bottom: 2px solid #855;
  }
  .content{
    padding: 0.5rem 1rem;
    display: flex;
    flex-direction: column;
    flex-grow: 1;
  }
  .content-apart{
    display: flex;
    justify-content: space-between;
    width: 100%;
  }
  .banner{
    padding: 0.5rem 1rem;
    background: #fec;
    width: calc(100% - 2rem);
  }
  @media (orientation: portrait) {
    .header{
      text-align: center;
      height: max-content;
    }
    .header-menu{
      font-size: min(5vw, 1em);
    }
    .header-menu-item{
      white-space: nowrap;
      width: max-content;
      padding-left: 2vw;
      margin: 0;
    }
  }
</style>
<div class="header">
  <div class="header-title"><a href="<?php echo $rel_pos; ?>/"><?php echo $inst_name; ?></a></div>
  <div class="header-menu">
    <div class="header-menu-item">
      <a class="header-menu-link" href="<?php echo $rel_pos; ?>/">Home</a>
    </div>
    <div class="header-menu-item">
      <a class="header-menu-link" href="<?php echo $rel_pos; ?>/database">Database</a>
    </div>
    <div class="header-menu-item">
      <a class="header-menu-link" href="<?php echo $rel_pos; ?>/settings">Settings</a>
    </div>
    <div class="header-menu-item">
      <a class="header-menu-link" href="<?php echo $rel_pos; ?>/logout">Logout</a>
    </div>
  </div>
</div>
<?php
if(isset($authed_user["info"])){
  $uname = htmlentities(base64_decode($authed_user["info"]["username"]));
  $loginuntil = date("l g:i A", strtotime($authed_user["info"]["login_until"]));
  echo(<<<HRD
<div class="banner noselect">Welcome <b>{$uname}</b>, you are logged in until <b>{$loginuntil}</b>.</div>
HRD);
}else{
  echo("<br>");
}
?>
