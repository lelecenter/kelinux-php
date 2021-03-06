<?php
/*
 * This file is part of Kelinux-php.
 * Copyright (C) 2012  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once 'model/ke_question.php';
require_once 'model/ke_answer.php';
require_once 'model/ke_community.php';

class question extends ke_controller
{
   public $question;
   public $can_edit;
   
   public function __construct()
   {
      parent::__construct('question', 'Pregunta');
   }
   
   protected function process()
   {
      if( isset($_GET['param1']) )
      {
         $this->question = new ke_question();
         $this->question = $this->question->get($_GET['param1']);
         
         if($this->question)
         {
            $this->title = $this->question->title().' ['.$this->question->get_status().']';
            
            $this->can_edit = FALSE;
            if( $this->user )
            {
               if( $this->user->is_admin() )
                  $this->can_edit = TRUE;
               else if($this->question->user)
               {
                  if($this->question->user_id == $this->user->id)
                     $this->can_edit = TRUE;
               }
            }
            
            if( isset($_POST['delete_question']) )
            {
               if(intval($_POST['delete_question']) == $this->question->id)
                  $this->delete_question();
               else if($_POST['delete_answer'] != '')
                  $this->delete_answer();
            }
            else if( isset($_POST['new_answer']) )
               $this->new_answer();
            else if( isset($_POST['add_reward']) )
               $this->add_reward();
            else if( isset($_POST['vote_answer']) )
               $this->vote_answer();
            else if( isset($_POST['mark_solution']) )
               $this->mark_solution();
            else if( isset($_POST['edit_question']) )
               $this->edit_question();
            else if( isset($_POST['edit_answer']) )
               $this->edit_answer();
            
            /// marcamos la pregunta como leída, solo si no ha sido eliminada, logicamente
            if($this->question)
               $this->question->mark_as_readed();
         }
         else
            $this->new_error("¡Pregunta no encontrada! Es posible que el enlace haya cambiado, usa el buscador.");
      }
      else
      {
         $this->question = FALSE;
         $this->new_error("¡Pregunta no encontrada! Es posible que el enlace haya cambiado, usa el buscador.");
      }
   }
   
   public function url()
   {
      if($this->question)
         return $this->question->url();
      else
         return KE_PATH.'question_list';
   }
   
   public function get_description()
   {
      if( $this->question )
         return $this->question->resume();
      else
         return $this->title;
   }
   
   public function get_tags()
   {
      if($this->question)
      {
         $tags = array();
         foreach($this->question->get_communities() as $c)
            $tags[] = $c->name;
         foreach($this->search->get_tags($this->question->text) as $t)
            $tags[] = $t;
         return join(', ', $tags);
      }
      else
         parent::get_tags();
   }
   
   private function add_reward()
   {
      $this->template = FALSE; /// desactivamos el motor de templates
      $mensaje = '';
      if( $this->question->is_solved() )
         $mensaje = "No puedes añadir recompensa a una pregunta solucionada.";
      else if( $this->user )
      {
         if( $this->user->points > 0 )
         {
            $reward = intval($_POST['add_reward']);
            if( $this->question->add_reward($reward) )
            {
               if( $this->user->add_points(0 - $reward) )
                  $mensaje = "OK;".$this->question->reward.";".$this->user->points;
               else
               {
                  $mensaje = "¡Error al descontarte los puntos!";
                  if( $this->is_db_history_enabled() )
                  {
                     foreach($this->db_history() as $h)
                        $mensaje .= "\n".$h;
                  }
               }
            }
            else
            {
               $mensaje = "¡Error al modificar la pregunta!";
               if( $this->is_db_history_enabled() )
               {
                  foreach($this->db_history() as $h)
                     $mensaje .= "\n".$h;
               }
            }
         }
         else
            $mensaje = "No tienes suficientes puntos para poder votar una pregunta.";
      }
      else
         $mensaje = "Debes iniciar sesión para poder votar una pregunta.";
      
      $this->log->new_line($mensaje);
      echo $mensaje;
   }
   
   private function edit_question()
   {
      if( intval($_POST['edit_question_id']) == $this->question->id )
      {
         if($this->can_edit)
         {
            $this->question->set_text($_POST['edit_question']);
            $this->question->status = intval($_POST['status']);
            
            /// eliminamos las comunidades a las que pertenece
            $cq = new ke_community_question();
            foreach($cq->all_from_question($this->question->id) as $cq2)
               $cq2->delete();
            /// añadimos las nuevas
            if( isset($_POST['community']) )
            {
               $cq->question_id = $this->question->id;
               foreach($_POST['community'] as $cid)
               {
                  $cq->community_id = $cid;
                  $cq->save();
               }
            }
            
            if( $this->question->save() )
               $this->new_message("Pregunta editada correctamente.");
            else
               $this->new_error("¡Imposible editar la pregunta!");
         }
         else
            $this->new_error("¡No tienes permiso para editar esta pregunta!");
      }
      else
         $this->new_error("¡La pregunta que quieres editar no es esta!");
   }
   
   private function delete_question()
   {
      if($this->user)
      {
         if( $this->user->is_admin() )
         {
            if( $this->question->delete() )
            {
               $this->new_message("Pregunta eliminada correctamente.");
               $this->question = FALSE;
            }
            else
               $this->new_error("¡Imposible eliminar la pregunta!");
         }
         else
            $this->new_error("No eres administrador. No puedes eliminar la pregunta.");
      }
      else
         $this->new_error("Debes iniciar sesión para eliminar la pregunta.");
   }

   private function new_answer()
   {
      $continuar = FALSE;
      if($this->user)
         $continuar = TRUE;
      else if( $this->captcha->solved() )
         $continuar = TRUE;
      else
         $this->new_error("Debes resolver el captcha para para contestar.");
      
      if($continuar)
      {
         $answer = new ke_answer();
         $answer->question_id = $this->question->id;
         $answer->num = ($this->question->num_answers+1);
         $answer->set_text($_POST['new_answer']);
         if($this->user)
            $answer->user_id = $this->user->id;
         
         if( $answer->save() )
            $this->new_message("Respuesta guardada correctamente");
         else
            $this->new_error("¡Imposible guardar la respuesta! ".$answer->errors);
      }
   }
   
   private function edit_answer()
   {
      if($this->user)
      {
         if( $this->user->is_admin() )
         {
            $answer = new ke_answer();
            $answer = $answer->get( intval($_POST['edit_answer_id']) );
            if($answer)
            {
               $answer->set_text( $_POST['edit_answer'] );
               if( $answer->save() )
                  $this->new_message("Respuesta editada correctamente.");
               else
                  $this->new_error("¡Imposible editar la respuesta!");
            }
            else
               $this->new_error("¡Respuesta no encontrada!");
         }
         else
            $this->new_error('No eres administrador. No puedes editar una respuesta.');
      }
      else
         $this->new_error('Debes iniciar sesión para poder editar una respuesta.');
   }
   
   private function delete_answer()
   {
      if($this->user)
      {
         if( $this->user->is_admin() )
         {
            $answer = new ke_answer();
            $answer = $answer->get( intval($_POST['delete_answer']) );
            if($answer)
            {
               if( $answer->delete() )
                  $this->new_message("Respuesta eliminada correctamente.");
               else
                  $this->new_error("¡Imposible eliminar la respuesta!");
            }
            else
               $this->new_error("¡Respuesta no encontrada!");
         }
         else
            $this->new_error('No eres administrador, no puedes eliminar una respuesta.');
      }
      else
         $this->new_error('Debes iniciar sesión para poder eliminar una respuesta.');
   }

   private function vote_answer()
   {
      $this->template = FALSE; /// desactivamos el motor de templates
      $mensaje = '';
      if( $this->user )
      {
         if( $this->user->points > 0 )
         {
            $answer = new ke_answer();
            $answer = $answer->get($_POST['vote_answer']);
            if($answer)
            {
               if( $answer->vote( intval($_POST['points']) ) )
               {
                  if($answer->user AND intval($_POST['points']) > 0)
                     $answer->user->add_points(1); /// los votos positivos suman un punto para el autor
                  
                  if( $this->user->add_points(-1) ) /// votar cuesta 1 punto
                     $mensaje = "OK;".$answer->id.";".$answer->grade.";".$this->user->points;
                  else
                  {
                     $mensaje = "¡Error al descontarte los puntos!";
                     if( $this->is_db_history_enabled() )
                     {
                        foreach($this->db_history() as $h)
                           $mensaje .= "\n".$h;
                     }
                  }
               }
               else
               {
                  $mensaje = "¡Error al modificar la respuesta!";
                  if( $this->is_db_history_enabled() )
                  {
                     foreach($this->db_history() as $h)
                        $mensaje .= "\n".$h;
                  }
               }
            }
            else
            {
               $mensaje = "¡Respuesta no encontrada!";
               if( $this->is_db_history_enabled() )
               {
                  foreach($this->db_history() as $h)
                     $mensaje .= "\n".$h;
               }
            }
         }
         else
            $mensaje = "No tienes suficientes puntos para poder votar una respuesta.";
      }
      else
         $mensaje = "Debes iniciar sesión para poder votar una respuesta.";
      
      $this->log->new_line($mensaje);
      echo $mensaje;
   }
   
   private function mark_solution()
   {
      $this->template = FALSE; /// desactivamos el motor de templates
      $mensaje = '';
      if($this->user)
      {
         /// solamente un administrador o el autor de la pregunta podrá modificarla
         $continuar = FALSE;
         if( $this->user->is_admin() )
            $continuar = TRUE;
         else if($this->question->user)
         {
            if($this->question->user_id == $this->user->id)
               $continuar = TRUE;
         }
         
         if($continuar)
         {
            $answer = new ke_answer();
            $answer = $answer->get($_POST['mark_solution']);
            if($answer)
            {
               if( $answer->vote( $this->question->reward ) )
               {
                  if($answer->user)
                     $answer->user->add_points( $this->question->reward );
                  
                  if( $this->question->set_solved() )
                     $mensaje = "OK";
                  else
                  {
                     $mensaje = "¡Error al marcar la pregunta como solucionada!";
                     if( $this->is_db_history_enabled() )
                     {
                        foreach($this->db_history() as $h)
                           $mensaje .= "\n".$h;
                     }
                  }
               }
               else
               {
                  $mensaje = "¡Error al marcar la respuesta!";
                  if( $this->is_db_history_enabled() )
                  {
                     foreach($this->db_history() as $h)
                        $mensaje .= "\n".$h;
                  }
               }
            }
            else
            {
               $mensaje = "¡Respuesta no encontrada!";
               if( $this->is_db_history_enabled() )
               {
                  foreach($this->db_history() as $h)
                     $mensaje .= "\n".$h;
               }
            }
         }
         else
            $mensaje = "No tienes permiso para poder marcar una pregunta como solucionada.";
      }
      else
         $mensaje = "Debes iniciar sesión para poder marcar una pregunta como solucionada.";
      
      $this->log->new_line($mensaje);
      echo $mensaje;
   }
}

?>
